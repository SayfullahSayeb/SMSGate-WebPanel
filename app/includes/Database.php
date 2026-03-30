<?php
/**
 * Database Class - PDO SQLite wrapper
 */

declare(strict_types=1);

class Database
{
    private static ?self $instance = null;
    private PDO $pdo;

    private function __construct()
    {
        $dsn = 'sqlite:' . DB_FILE;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $this->pdo = new PDO($dsn, null, null, $options);
        $this->initialize();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    private function initialize(): void
    {
        $this->createTables();
        $this->seedDefaultData();
    }

    private function createTables(): void
    {
        // Run migrations first to add new columns
        $this->runMigrations();
        
        $sql = "
            -- Users table for admin authentication
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                password TEXT NOT NULL,
                email TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                last_login TEXT,
                is_active INTEGER DEFAULT 1
            );

            -- SMS Queue table
            CREATE TABLE IF NOT EXISTS sms_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                phone_number TEXT NOT NULL,
                message TEXT NOT NULL,
                sim_selection TEXT DEFAULT 'auto',
                priority INTEGER DEFAULT 5,
                status TEXT DEFAULT 'pending',
                attempts INTEGER DEFAULT 0,
                max_attempts INTEGER DEFAULT 3,
                error_message TEXT,
                external_id TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                scheduled_at TEXT,
                sent_at TEXT,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            -- Sent messages history
            CREATE TABLE IF NOT EXISTS sent_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                queue_id INTEGER,
                phone_number TEXT NOT NULL,
                message TEXT NOT NULL,
                sim_selection TEXT,
                priority INTEGER,
                external_id TEXT,
                status TEXT NOT NULL,
                error_message TEXT,
                sent_at TEXT DEFAULT CURRENT_TIMESTAMP,
                delivered_at TEXT,
                FOREIGN KEY (queue_id) REFERENCES sms_queue(id)
            );

            -- Rate limiting tracking
            CREATE TABLE IF NOT EXISTS rate_limits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip_address TEXT NOT NULL,
                action TEXT NOT NULL,
                count INTEGER DEFAULT 1,
                window_start TEXT NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            -- Settings table
            CREATE TABLE IF NOT EXISTS settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                setting_key TEXT UNIQUE NOT NULL,
                setting_value TEXT,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            -- Indexes for performance
            CREATE INDEX IF NOT EXISTS idx_queue_status ON sms_queue(status);
            CREATE INDEX IF NOT EXISTS idx_queue_scheduled ON sms_queue(scheduled_at);
            CREATE INDEX IF NOT EXISTS idx_rate_limits_window ON rate_limits(window_start);
            CREATE INDEX IF NOT EXISTS idx_sent_messages_date ON sent_messages(sent_at);
            CREATE INDEX IF NOT EXISTS idx_sent_messages_delivered ON sent_messages(delivered_at);

            -- Message templates
            CREATE TABLE IF NOT EXISTS templates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                subject TEXT,
                content TEXT NOT NULL,
                variables TEXT,
                category TEXT DEFAULT 'general',
                usage_count INTEGER DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            -- Contact groups
            CREATE TABLE IF NOT EXISTS contact_groups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                description TEXT,
                color TEXT DEFAULT '#6B7280',
                contact_count INTEGER DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            -- Contacts
            CREATE TABLE IF NOT EXISTS contacts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                phone TEXT NOT NULL,
                name TEXT,
                email TEXT,
                company TEXT,
                notes TEXT,
                tags TEXT,
                group_id INTEGER,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (group_id) REFERENCES contact_groups(id) ON DELETE SET NULL
            );

            -- Webhook logs for received SMS and callbacks
            CREATE TABLE IF NOT EXISTS webhook_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type TEXT NOT NULL,
                payload TEXT NOT NULL,
                source_phone TEXT,
                message TEXT,
                status TEXT,
                processed INTEGER DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            -- Bulk send batches
            CREATE TABLE IF NOT EXISTS bulk_batches (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                filename TEXT,
                total_recipients INTEGER DEFAULT 0,
                sent_count INTEGER DEFAULT 0,
                failed_count INTEGER DEFAULT 0,
                status TEXT DEFAULT 'pending',
                created_by INTEGER,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                completed_at TEXT
            );

            -- Indexes for new tables
            CREATE INDEX IF NOT EXISTS idx_templates_category ON templates(category);
            CREATE INDEX IF NOT EXISTS idx_contacts_phone ON contacts(phone);
            CREATE INDEX IF NOT EXISTS idx_contacts_group ON contacts(group_id);
            CREATE INDEX IF NOT EXISTS idx_webhook_processed ON webhook_logs(processed, created_at);
            CREATE INDEX IF NOT EXISTS idx_bulk_status ON bulk_batches(status);
        ";

        $this->pdo->exec($sql);
    }
    
    private function runMigrations(): void
    {
        $migrations = [
            'delivered_at_sent_messages' => "ALTER TABLE sent_messages ADD COLUMN delivered_at TEXT",
            'updated_at_sent_messages' => "ALTER TABLE sent_messages ADD COLUMN updated_at TEXT",
            'error_message_webhook_logs' => "ALTER TABLE webhook_logs ADD COLUMN error_message TEXT",
            'device_id_sms_queue' => "ALTER TABLE sms_queue ADD COLUMN device_id TEXT",
            'device_id_sent_messages' => "ALTER TABLE sent_messages ADD COLUMN device_id TEXT",
            'device_id_received_messages' => "ALTER TABLE received_messages ADD COLUMN device_id TEXT",
            'api_url_devices' => "ALTER TABLE devices ADD COLUMN api_url TEXT",
            'api_login_devices' => "ALTER TABLE devices ADD COLUMN api_login TEXT",
            'api_password_devices' => "ALTER TABLE devices ADD COLUMN api_password TEXT",
            'webhook_url_devices' => "ALTER TABLE devices ADD COLUMN webhook_url TEXT",
        ];
        
        foreach ($migrations as $name => $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (PDOException $e) {
                // Column might already exist, ignore error
            }
        }
        
        $this->createReceivedMessagesTable();
        $this->createAutomationTable();
        $this->createDevicesTable();
    }
    
    private function createDevicesTable(): void
    {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS devices (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    device_id TEXT UNIQUE NOT NULL,
                    friendly_name TEXT,
                    name TEXT,
                    status TEXT DEFAULT 'offline',
                    last_seen TEXT,
                    battery INTEGER,
                    model TEXT,
                    manufacturer TEXT,
                    app_version TEXT,
                    sim_info TEXT,
                    api_url TEXT,
                    api_login TEXT,
                    api_password TEXT,
                    webhook_url TEXT,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_devices_device_id ON devices(device_id)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_devices_status ON devices(status)");
        } catch (PDOException $e) {
            // Table might already exist
        }
    }
    
    private function createReceivedMessagesTable(): void
    {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS received_messages (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    phone_number TEXT NOT NULL,
                    message TEXT NOT NULL,
                    sim_slot INTEGER,
                    is_read INTEGER DEFAULT 0,
                    received_at TEXT DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_received_date ON received_messages(received_at)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_received_phone ON received_messages(phone_number)");
        } catch (PDOException $e) {
            // Table might already exist
        }
    }
    
    private function createAutomationTable(): void
    {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS auto_reply_rules (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    trigger_keyword TEXT NOT NULL,
                    match_type TEXT DEFAULT 'exact' CHECK(match_type IN ('exact', 'contains', 'starts_with', 'regex')),
                    reply_message TEXT NOT NULL,
                    enabled INTEGER DEFAULT 1,
                    priority INTEGER DEFAULT 10,
                    case_sensitive INTEGER DEFAULT 0,
                    max_replies_per_day INTEGER DEFAULT 0,
                    reply_count_today INTEGER DEFAULT 0,
                    last_reply_at TEXT,
                    trigger_count INTEGER DEFAULT 0,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_automation_keyword ON auto_reply_rules(trigger_keyword)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_automation_enabled ON auto_reply_rules(enabled)");
            
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS auto_reply_logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    rule_id INTEGER NOT NULL,
                    phone_number TEXT NOT NULL,
                    received_message TEXT NOT NULL,
                    reply_message TEXT NOT NULL,
                    sent INTEGER DEFAULT 0,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (rule_id) REFERENCES auto_reply_rules(id)
                )
            ");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_auto_reply_logs_rule ON auto_reply_logs(rule_id)");
        } catch (PDOException $e) {
            // Table might already exist
        }
    }

    private function seedDefaultData(): void
    {
        // Check if admin user exists
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM users WHERE username = 'admin'");
        $stmt->execute();
        
        if ((int)$stmt->fetchColumn() === 0) {
            // Create default admin user (password: admin123 - CHANGE IN PRODUCTION!)
            $hashedPassword = password_hash('admin123', PASSWORD_DEFAULT);
            $stmt = $this->pdo->prepare("INSERT INTO users (username, password, email) VALUES ('admin', ?, 'admin@localhost')");
            $stmt->execute([$hashedPassword]);
        }

        // Seed default settings
        $defaultSettings = [
            'api_url' => 'https://api.sms-gate.app/3rdparty/v1',
            'api_login' => '',
            'api_password' => '',
            'rate_per_minute' => DEFAULT_RATE_LIMIT_PER_MINUTE,
            'rate_per_hour' => DEFAULT_RATE_LIMIT_PER_HOUR,
            'rate_per_day' => DEFAULT_RATE_LIMIT_PER_DAY,
            'delay_between_sms' => DEFAULT_DELAY_BETWEEN_SMS,
            'default_sim' => 'auto',
            'default_priority' => '5',
        ];

        foreach ($defaultSettings as $key => $value) {
            $stmt = $this->pdo->prepare("INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)");
            $stmt->execute([$key, $value]);
        }
    }

    public function prepare(string $sql): PDOStatement
    {
        return $this->pdo->prepare($sql);
    }

    public function query(string $sql): PDOStatement
    {
        return $this->pdo->query($sql);
    }
    
    public function exec(string $sql): int
    {
        return $this->pdo->exec($sql);
    }

    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }
}
