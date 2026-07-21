<?php
// app/Controllers/AdminTicketController.php

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/app/Services/TicketLog.php';

class AdminTicketController {
    private PDO $pdo;

    public function __construct() {
        $this->pdo = get_pdo();
    }

    public function index(): void {
        $reason = $_GET['reason'] ?? '';
        if (!in_array($reason, ['', 'manual', 'purchase', 'use', 'return'], true)) {
            $reason = '';
        }

        $ticketLogs = TicketLog::recent($this->pdo, 300, 0, $reason);
        $summary = TicketLog::summary($this->pdo);

        require BASE_PATH . '/app/Views/admin/tickets.php';
    }
}
