<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Install\Installer;
use App\Migration\Migrator;
use App\Support\Config;
use App\Support\Database;
use App\Support\View;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class DashboardController
{
    public function __construct(
        private readonly View $view,
        private readonly Migrator $migrator,
        private readonly Config $config,
        private readonly PDO $db,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $status = $this->migrator->status();
        $prefix = (string) $this->config->get('db.prefix', '');
        $lock = Installer::readLock();

        $counts = $this->db->query(sprintf(
            'SELECT role, COUNT(*) AS total FROM `%susers` GROUP BY role',
            $prefix
        ))->fetchAll();

        return $this->view->render($response, 'dashboard', [
            'user' => $request->getAttribute('user'),
            'page' => 'dashboard',
            'server' => Database::inspectServer($this->db),
            'phpVersion' => PHP_VERSION,
            'userCounts' => array_column($counts, 'total', 'role'),
            'migrationSummary' => [
                'ran' => count($status['ran']),
                'pending' => count($status['pending']),
                'missing' => count($status['missing']),
            ],
            'installedAt' => $lock['installed_at'] ?? null,
        ]);
    }
}
