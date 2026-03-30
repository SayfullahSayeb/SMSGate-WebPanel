<?php
/**
 * Device Service - Manage devices from SMS-Gate.app API
 */

declare(strict_types=1);

class DeviceService
{
    private Database $db;
    private SMSService $smsService;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->smsService = new SMSService($this->db);
    }

    public function getAllDevices(): array
    {
        $stmt = $this->db->prepare("SELECT * FROM devices ORDER BY friendly_name ASC, name ASC");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function addDevice(string $deviceId, string $friendlyName, string $apiUrl, string $apiLogin, string $apiPassword, string $webhookUrl): array
    {
        $status = 'offline';
        
        if (!empty($apiUrl) && !empty($apiLogin) && !empty($apiPassword)) {
            try {
                $url = rtrim($apiUrl, '/');
                
                if (strpos($url, 'api.sms-gate.app') !== false && strpos($url, '/3rdparty/v1') === false) {
                    $url .= '/3rdparty/v1';
                }
                
                $client = new \AndroidSmsGateway\Client($apiLogin, $apiPassword, $url);
                $health = $client->HealthCheck();
                $status = 'online';
            } catch (Exception $e) {
                error_log('Device connection test failed: ' . $e->getMessage());
                $status = 'offline';
            }
        }
        
        $lastSeen = $status === 'online' ? date('Y-m-d H:i:s') : null;
        
        $existing = $this->getDeviceById($deviceId);
        
        if ($existing) {
            $stmt = $this->db->prepare("
                UPDATE devices SET 
                    friendly_name = ?,
                    api_url = ?,
                    api_login = ?,
                    api_password = ?,
                    webhook_url = ?,
                    status = ?,
                    last_seen = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE device_id = ?
            ");
            $stmt->execute([$friendlyName, $apiUrl, $apiLogin, $apiPassword, $webhookUrl, $status, $lastSeen, $deviceId]);
            
            return [
                'success' => true,
                'message' => $status === 'online' ? 'Device added successfully (Online)' : 'Device added successfully'
            ];
        }

        $stmt = $this->db->prepare("
            INSERT INTO devices (device_id, friendly_name, name, api_url, api_login, api_password, webhook_url, status, last_seen)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        try {
            $stmt->execute([
                $deviceId,
                $friendlyName,
                $friendlyName,
                $apiUrl,
                $apiLogin,
                $apiPassword,
                $webhookUrl,
                $status,
                $lastSeen
            ]);
            
            return [
                'success' => true,
                'message' => $status === 'online' ? 'Device added successfully (Online)' : 'Device added successfully'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function updateDevice(string $deviceId, string $friendlyName, string $apiUrl, string $apiLogin, ?string $apiPassword, string $webhookUrl): array
    {
        $existing = $this->getDeviceById($deviceId);
        
        if (!$existing) {
            return [
                'success' => false,
                'error' => 'Device not found'
            ];
        }

        if ($apiPassword && !empty($apiPassword)) {
            $stmt = $this->db->prepare("
                UPDATE devices SET 
                    friendly_name = ?,
                    api_url = ?,
                    api_login = ?,
                    api_password = ?,
                    webhook_url = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE device_id = ?
            ");
            $stmt->execute([$friendlyName, $apiUrl, $apiLogin, $apiPassword, $webhookUrl, $deviceId]);
        } else {
            $stmt = $this->db->prepare("
                UPDATE devices SET 
                    friendly_name = ?,
                    api_url = ?,
                    api_login = ?,
                    webhook_url = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE device_id = ?
            ");
            $stmt->execute([$friendlyName, $apiUrl, $apiLogin, $webhookUrl, $deviceId]);
        }

        return [
            'success' => true,
            'message' => 'Device updated successfully'
        ];
    }

    public function getDeviceById(string $deviceId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM devices WHERE device_id = ?");
        $stmt->execute([$deviceId]);
        return $stmt->fetch() ?: null;
    }

    public function getDeviceClient(string $deviceId): ?object
    {
        $device = $this->getDeviceById($deviceId);
        
        if (!$device || empty($device['api_url']) || empty($device['api_login']) || empty($device['api_password'])) {
            return null;
        }

        try {
            $apiUrl = rtrim($device['api_url'], '/');
            
            if (strpos($apiUrl, 'api.sms-gate.app') !== false && strpos($apiUrl, '/3rdparty/v1') === false) {
                $apiUrl .= '/3rdparty/v1';
            }
            
            $client = new \AndroidSmsGateway\Client(
                $device['api_login'],
                $device['api_password'],
                $apiUrl
            );
            return $client;
        } catch (Exception $e) {
            error_log('Device Client Error: ' . $e->getMessage());
            return null;
        }
    }

    public function refreshDevices(): array
    {
        $devices = $this->getAllDevices();
        
        if (empty($devices)) {
            return [
                'success' => false,
                'error' => 'No devices configured. Add a device first.'
            ];
        }

        $online = 0;
        $offline = 0;

        foreach ($devices as $device) {
            if (empty($device['api_url']) || empty($device['api_login']) || empty($device['api_password'])) {
                $offline++;
                continue;
            }

            try {
                $apiUrl = rtrim($device['api_url'], '/');
                
                if (strpos($apiUrl, 'api.sms-gate.app') !== false && strpos($apiUrl, '/3rdparty/v1') === false) {
                    $apiUrl .= '/3rdparty/v1';
                }
                
                $client = new \AndroidSmsGateway\Client(
                    $device['api_login'],
                    $device['api_password'],
                    $apiUrl
                );
                
                $client->HealthCheck();
                
                $stmt = $this->db->prepare("
                    UPDATE devices SET 
                        status = 'online',
                        last_seen = CURRENT_TIMESTAMP,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE device_id = ?
                ");
                $stmt->execute([$device['device_id']]);
                $online++;

            } catch (Exception $e) {
                $stmt = $this->db->prepare("
                    UPDATE devices SET status = 'offline', updated_at = CURRENT_TIMESTAMP WHERE device_id = ?
                ");
                $stmt->execute([$device['device_id']]);
                $offline++;
            }
        }

        return [
            'success' => true,
            'online' => $online,
            'offline' => $offline,
            'message' => "Online: $online, Offline: $offline"
        ];
    }

    public function updateFriendlyName(string $deviceId, string $friendlyName): array
    {
        $existing = $this->getDeviceById($deviceId);
        
        if (!$existing) {
            return [
                'success' => false,
                'error' => 'Device not found'
            ];
        }

        $stmt = $this->db->prepare("
            UPDATE devices SET friendly_name = ?, updated_at = CURRENT_TIMESTAMP WHERE device_id = ?
        ");
        $stmt->execute([$friendlyName, $deviceId]);

        return [
            'success' => true,
            'message' => 'Device name updated'
        ];
    }

    public function deleteDevice(string $deviceId): array
    {
        $existing = $this->getDeviceById($deviceId);
        
        if (!$existing) {
            return [
                'success' => false,
                'error' => 'Device not found'
            ];
        }

        $stmt = $this->db->prepare("DELETE FROM devices WHERE device_id = ?");
        $stmt->execute([$deviceId]);

        return [
            'success' => true,
            'message' => 'Device removed from panel'
        ];
    }

    public function updateDeviceStatus(string $deviceId, array $data): void
    {
        $existing = $this->getDeviceById($deviceId);
        
        if ($existing) {
            $fields = [];
            $params = [];

            if (isset($data['status'])) {
                $fields[] = 'status = ?';
                $params[] = $data['status'];
            }
            if (isset($data['last_seen'])) {
                $fields[] = 'last_seen = ?';
                $params[] = $data['last_seen'];
            }
            if (isset($data['battery'])) {
                $fields[] = 'battery = ?';
                $params[] = $data['battery'];
            }
            if (isset($data['model'])) {
                $fields[] = 'model = ?';
                $params[] = $data['model'];
            }
            if (isset($data['manufacturer'])) {
                $fields[] = 'manufacturer = ?';
                $params[] = $data['manufacturer'];
            }
            if (isset($data['app_version'])) {
                $fields[] = 'app_version = ?';
                $params[] = $data['app_version'];
            }
            if (isset($data['sim_info'])) {
                $fields[] = 'sim_info = ?';
                $params[] = $data['sim_info'];
            }

            if (!empty($fields)) {
                $fields[] = 'updated_at = CURRENT_TIMESTAMP';
                $params[] = $deviceId;

                $sql = "UPDATE devices SET " . implode(', ', $fields) . " WHERE device_id = ?";
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
            }
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO devices (device_id, name, friendly_name, status, last_seen, battery, model, manufacturer, app_version, sim_info)
                VALUES (?, ?, ?, 'online', ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $deviceId,
                $data['name'] ?? 'Unknown Device',
                $data['name'] ?? 'Unknown Device',
                $data['last_seen'] ?? date('Y-m-d H:i:s'),
                $data['battery'] ?? null,
                $data['model'] ?? null,
                $data['manufacturer'] ?? null,
                $data['app_version'] ?? null,
                $data['sim_info'] ?? null
            ]);
        }
    }

    public function getDeviceStats(): array
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM devices");
        $stmt->execute();
        $total = (int)$stmt->fetchColumn();

        $stmt = $this->db->prepare("SELECT COUNT(*) as online FROM devices WHERE status = 'online'");
        $stmt->execute();
        $online = (int)$stmt->fetchColumn();

        return [
            'total' => $total,
            'online' => $online,
            'offline' => $total - $online
        ];
    }
}
