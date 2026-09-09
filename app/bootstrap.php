<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Migration\Migrator;
use App\Support\Config;
use App\Support\Database;
use App\Support\Paths;
use App\Support\Url;
use App\Support\View;
use DI\ContainerBuilder;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Slim\Factory\AppFactory;

require_once Paths::vendorAutoload();

$config = Config::load();

date_default_timezone_set((string) $config->get('app.timezone', 'Asia/Bangkok'));

$builder = new ContainerBuilder();
$builder->addDefinitions([
    Config::class => static fn (): Config => $config,

    PDO::class => static fn (Config $config): PDO => Database::connect($config->get('db')),

    Auth::class => static fn (PDO $db, Config $config): Auth => new Auth($db, (string) $config->get('db.prefix', '')),

    Migrator::class => static fn (PDO $db, Config $config): Migrator => new Migrator($db, (string) $config->get('db.prefix', '')),

    LoggerInterface::class => static function (): LoggerInterface {
        $logger = new Logger('rvc');
        $logger->pushHandler(new StreamHandler(Paths::storage('logs/app.log'), Logger::WARNING));

        return $logger;
    },

    View::class => static fn (Config $config): View => new View([
        'appName' => $config->get('app.name'),
        'college' => $config->get('app.college'),
        'version' => $config->get('app.version'),
        'base' => Url::base(),
        'user' => null,
    ]),
]);

$container = $builder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();

$basePath = rtrim((string) (parse_url((string) $config->get('app.url'), PHP_URL_PATH) ?: ''), '/');
$app->setBasePath($basePath);
Url::setBase($basePath);

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

$debug = (bool) $config->get('app.debug', false);
$errorMiddleware = $app->addErrorMiddleware($debug, true, true, $container->get(LoggerInterface::class));

$errorMiddleware->setDefaultErrorHandler(
    function (ServerRequestInterface $request, Throwable $exception) use ($app, $container, $debug): ResponseInterface {
        $status = $exception instanceof HttpException ? $exception->getCode() : 500;

        [$title, $message] = match ($status) {
            403 => ['ไม่มีสิทธิ์เข้าหน้านี้', 'บัญชีของคุณไม่ได้รับสิทธิ์ให้เข้าถึงส่วนนี้ กรุณาติดต่อผู้ดูแลระบบ'],
            404 => ['ไม่พบหน้าที่ต้องการ', 'ลิงก์อาจพิมพ์ผิดหรือหน้านี้ถูกย้ายไปแล้ว'],
            405 => ['เรียกใช้งานไม่ถูกวิธี', 'กรุณากลับไปเริ่มจากเมนูของระบบ'],
            default => ['ระบบขัดข้อง', 'เกิดข้อผิดพลาดที่ไม่คาดคิด ระบบได้บันทึกไว้แล้ว กรุณาลองใหม่อีกครั้ง'],
        };

        return $container->get(View::class)->render(
            $app->getResponseFactory()->createResponse($status),
            'error',
            [
                'title' => $title,
                'message' => $message,
                'detail' => $debug ? $exception->getMessage() : null,
            ]
        );
    }
);

(require Paths::root() . '/app/routes.php')($app);

return $app;
