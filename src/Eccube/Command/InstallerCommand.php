<?php

/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) EC-CUBE CO.,LTD. All Rights Reserved.
 *
 * http://www.ec-cube.co.jp/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Eccube\Command;

use Doctrine\DBAL\DriverManager;
use Dotenv\Dotenv;
use Eccube\Util\StringUtil;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class InstallerCommand extends Command
{
    protected static $defaultName = 'eccube:install';

    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var SymfonyStyle
     */
    protected $io;

    /**
     * @var string
     */
    protected $databaseUrl;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct();

        $this->container = $container;
    }

    protected function configure()
    {
        $this
            ->setDescription('Install EC-CUBE');
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        $this->io->title('EC-CUBE Installer Interactive Wizard');
        $this->io->text([
            'If you prefer to not use this interactive wizard, define the environment valiables as follows:',
            '',
            ' $ export APP_ENV=dev',
            ' $ export APP_DEBUG=1',
            ' $ export DATABASE_URL=database_url',
            ' $ export DATABASE_SERVER_VERSION=server_version',
            ' $ export MAILER_URL=mailer_url',
            ' $ export ECCUBE_AUTH_MAGIC=auth_magic',
            ' ... and more',
            ' $ php bin/console eccube:install --no-interaction',
            '',
        ]);

        // DATABASE_URL
        $databaseUrl = $this->container->getParameter('eccube_database_url');
        if (empty($databaseUrl)) {
            $databaseUrl = 'sqlite:///var/eccube.db';
        }
        $databaseUrl = $this->io->ask('Database Url', $databaseUrl);

        // DATABASE_SERVER_VERSION
        $serverVersion = $this->getDatabaseServerVersion($databaseUrl);

        // MAILER_URL
        $mailerUrl = $this->container->getParameter('eccube_mailer_url');
        if (empty($mailerUrl)) {
            $mailerUrl = 'null://localhost';
        }
        $mailerUrl = $this->io->ask('Mailer Url', $mailerUrl);

        // ECCUBE_AUTH_MAGIC
        $authMagic = $this->container->getParameter('eccube_auth_magic');
        if (empty($authMagic) || $authMagic === '<change.me>') {
            $authMagic = StringUtil::random();
        }
        $authMagic = $this->io->ask('Auth Magic', $authMagic);

        // 以下環境変数に規定済の設定値があれば利用する
        // APP_ENV
        $appEnv = env('APP_ENV', 'dev');
        // .envが存在しない状態では規定値'install'となっているため、
        if ($appEnv === 'install') {
            $appEnv = 'dev';
        }

        // APP_DEBUG
        $appDebug = env('APP_DEBUG','1');

        // ECCUBE_ADMIN_ROUTE
        $adminRoute = $this->container->getParameter('eccube_admin_route');
        if (empty($adminRoute)) {
            $adminRoute = 'admin';
        }

        // ECCUBE_TEMPLATE_CODE
        $templateCode = $this->container->getParameter('eccube_theme_code');
        if (empty($templateCode)) {
            $templateCode = 'default';
        }

        // ECCUBE_LOCALE
        $locale = $this->container->getParameter('locale');
        if (empty($locale)) {
            $locale = 'ja';
        }

        $this->io->caution('Execute the installation process. All data is initialized.');
        $question = new ConfirmationQuestion('Is it OK?');
        if (!$this->io->askQuestion($question)) {
            // `no`の場合はキャンセルメッセージを出力して終了する
            $this->setCode(function () {
                $this->io->success('EC-CUBE installation stopped.');
            });

            return;
        }

        $envParameters = [
            'APP_ENV' => $appEnv,
            'APP_DEBUG' => $appDebug,
            'DATABASE_URL' => $databaseUrl,
            'DATABASE_SERVER_VERSION' => $serverVersion,
            'MAILER_URL' => $mailerUrl,
            'ECCUBE_AUTH_MAGIC' => $authMagic,
            'ECCUBE_ADMIN_ROUTE' => $adminRoute,
            'ECCUBE_TEMPLATE_CODE' => $templateCode,
            'ECCUBE_LOCALE' => $locale,
        ];

        $envDir = $this->container->getParameter('kernel.project_dir');
        $envFile = $envDir.'/.env';
        $envDistFile = $envDir.'/.env.dist';

        $env = file_exists($envFile)
            ? file_get_contents($envFile)
            : file_get_contents($envDistFile);

        $env = StringUtil::replaceOrAddEnv($env, $envParameters);

        file_put_contents($envFile, $env);
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->io = new SymfonyStyle($input, $output);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Process実行時に, APP_ENV/APP_DEBUGが子プロセスに引き継がれてしまうため,
        // 生成された.envをロードして上書きする.
        if ($input->isInteractive()) {
            $envDir = $this->container->getParameter('kernel.project_dir');
            if (file_exists($envDir.'/.env')) {
                (new Dotenv($envDir))->overload();
            }
        }

        // 対話モード実行時, container->getParameter('eccube_database_url')では
        // 更新後の値が取得できないため, getenv()を使用する.
        $databaseUrl = getenv('DATABASE_URL');
        $databaseName = $this->getDatabaseName($databaseUrl);
        $ifNotExists = $databaseName === 'sqlite' ? '' : ' --if-not-exists';

        // データベース作成, スキーマ作成, 初期データの投入を行う.
        $commands = [
            'doctrine:database:create'.$ifNotExists,
            'doctrine:schema:drop --force',
            'doctrine:schema:create',
            'eccube:fixtures:load',
            'cache:clear --no-warmup',
        ];

        // コンテナを再ロードするため別プロセスで実行する.
        foreach ($commands as $command) {
            try {
                $this->io->text(sprintf('<info>Run %s</info>...', $command));
                $process = new Process('bin/console '.$command);
                $process->mustRun();
                $this->io->text($process->getOutput());
            } catch (ProcessFailedException $e) {
                $this->io->error($e->getMessage());

                return;
            }
        }

        $this->io->success('EC-CUBE installation successful.');
    }

    protected function getDatabaseName($databaseUrl)
    {
        if (0 === strpos($databaseUrl, 'sqlite')) {
            return 'sqlite';
        }
        if (0 === strpos($databaseUrl, 'postgres')) {
            return 'postgres';
        }
        if (0 === strpos($databaseUrl, 'mysql')) {
            return 'mysql';
        }

        throw new \LogicException(sprintf('Database Url %s is invalid.', $databaseUrl));
    }

    protected function getDatabaseServerVersion($databaseUrl)
    {
        try {
            $conn = DriverManager::getConnection([
                'url' => $databaseUrl,
            ]);
        } catch (\Exception $e) {
            throw new \LogicException(sprintf('Database Url %s is invalid.', $databaseUrl));
        }
        $platform = $conn->getDatabasePlatform()->getName();
        switch ($platform) {
            case 'sqlite':
                $sql = 'SELECT sqlite_version() AS server_version';
                break;
            case 'mysql':
                $sql = 'SELECT version() AS server_version';
                break;
            case 'postgresql':
            default:
                $sql = 'SHOW server_version';
        }
        $stmt = $conn->executeQuery($sql);
        $version = $stmt->fetchColumn();

        if ($platform === 'postgresql') {
            preg_match('/\A([\d+\.]+)/', $version, $matches);
            $version = $matches[1];
        }

        return $version;
    }
}
