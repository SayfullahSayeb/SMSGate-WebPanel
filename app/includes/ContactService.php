<?php
/**
 * Contact Service - Contact and group management
 */

declare(strict_types=1);

class ContactService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    // ========== CONTACTS ==========

    public function getAllContacts(array $filters = []): array
    {
        $sql = "SELECT c.*, cg.name as group_name, cg.color as group_color 
                FROM contacts c 
                LEFT JOIN contact_groups cg ON c.group_id = cg.id 
                WHERE 1=1";
        $params = [];

        if (!empty($filters['group_id'])) {
            $sql .= " AND c.group_id = ?";
            $params[] = $filters['group_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (c.phone LIKE ? OR c.name LIKE ? OR c.email LIKE ? OR c.company LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        if (!empty($filters['tag'])) {
            $sql .= " AND c.tags LIKE ?";
            $params[] = '%"' . $filters['tag'] . '"%';
        }

        $sql .= " ORDER BY c.name ASC, c.phone ASC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = (int)$filters['limit'];
            $params[] = (int)($filters['offset'] ?? 0);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getContactById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM contacts WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function getContactByPhone(string $phone): ?array
    {
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        $stmt = $this->db->prepare("SELECT * FROM contacts WHERE phone = ?");
        $stmt->execute([$phone]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function createContact(array $data): array
    {
        $phone = preg_replace('/[^0-9+]/', '', $data['phone']);
        
        if (empty($phone)) {
            return ['success' => false, 'error' => 'Invalid phone number'];
        }

        $stmt = $this->db->prepare("
            INSERT INTO contacts (phone, name, email, company, notes, tags, group_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $phone,
            $data['name'] ?? null,
            $data['email'] ?? null,
            $data['company'] ?? null,
            $data['notes'] ?? null,
            isset($data['tags']) ? json_encode($data['tags']) : null,
            $data['group_id'] ?? null
        ]);

        if ($result) {
            $id = $this->db->lastInsertId();
            $this->updateGroupCount($data['group_id'] ?? null);
            return ['success' => true, 'id' => $id];
        }

        return ['success' => false, 'error' => 'Failed to create contact'];
    }

    public function updateContact(int $id, array $data): array
    {
        $oldContact = $this->getContactById($id);
        $oldGroupId = $oldContact['group_id'] ?? null;
        
        $phone = preg_replace('/[^0-9+]/', '', $data['phone'] ?? $oldContact['phone']);
        
        $stmt = $this->db->prepare("
            UPDATE contacts SET 
                phone = ?, 
                name = ?, 
                email = ?, 
                company = ?,
                notes = ?,
                tags = ?,
                group_id = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        
        $result = $stmt->execute([
            $phone,
            $data['name'] ?? null,
            $data['email'] ?? null,
            $data['company'] ?? null,
            $data['notes'] ?? null,
            isset($data['tags']) ? json_encode($data['tags']) : null,
            $data['group_id'] ?? null,
            $id
        ]);

        if ($result) {
            if ($oldGroupId != ($data['group_id'] ?? null)) {
                $this->updateGroupCount($oldGroupId);
                $this->updateGroupCount($data['group_id'] ?? null);
            }
            return ['success' => true];
        }

        return ['success' => false, 'error' => 'Failed to update contact'];
    }

    public function deleteContact(int $id): bool
    {
        $contact = $this->getContactById($id);
        $groupId = $contact['group_id'] ?? null;
        
        $stmt = $this->db->prepare("DELETE FROM contacts WHERE id = ?");
        $result = $stmt->execute([$id]);
        
        if ($result && $groupId) {
            $this->updateGroupCount($groupId);
        }
        
        return $result;
    }

    public function deleteContacts(array $ids): int
    {
        if (empty($ids)) return 0;
        
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("DELETE FROM contacts WHERE id IN ($placeholders)");
        $result = $stmt->execute($ids);
        
        return $result ? count($ids) : 0;
    }

    public function importContacts(array $contacts): array
    {
        $imported = 0;
        $duplicates = 0;
        $errors = 0;

        foreach ($contacts as $contact) {
            $existing = $this->getContactByPhone($contact['phone'] ?? '');
            if ($existing) {
                $duplicates++;
                continue;
            }

            $result = $this->createContact($contact);
            if ($result['success']) {
                $imported++;
            } else {
                $errors++;
            }
        }

        return [
            'imported' => $imported,
            'duplicates' => $duplicates,
            'errors' => $errors,
            'total' => count($contacts)
        ];
    }

    public function getContactCount(): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM contacts");
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    public function getContactsByGroup(int $groupId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM contacts WHERE group_id = ? ORDER BY name ASC");
        $stmt->execute([$groupId]);
        return $stmt->fetchAll();
    }

    // ========== GROUPS ==========

    public function getAllGroups(): array
    {
        $stmt = $this->db->prepare("SELECT * FROM contact_groups ORDER BY name ASC");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getGroupById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM contact_groups WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function createGroup(array $data): array
    {
        $stmt = $this->db->prepare("
            INSERT INTO contact_groups (name, description, color)
            VALUES (?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $data['name'],
            $data['description'] ?? null,
            $data['color'] ?? '#6B7280'
        ]);

        if ($result) {
            return ['success' => true, 'id' => $this->db->lastInsertId()];
        }

        return ['success' => false, 'error' => 'Failed to create group'];
    }

    public function updateGroup(int $id, array $data): array
    {
        $stmt = $this->db->prepare("
            UPDATE contact_groups SET 
                name = ?, 
                description = ?,
                color = ?
            WHERE id = ?
        ");
        
        $result = $stmt->execute([
            $data['name'],
            $data['description'] ?? null,
            $data['color'] ?? '#6B7280',
            $id
        ]);

        return ['success' => (bool)$result];
    }

    public function deleteGroup(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE contacts SET group_id = NULL WHERE group_id = ?");
        $stmt->execute([$id]);
        
        $stmt = $this->db->prepare("DELETE FROM contact_groups WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function updateGroupCount(?int $groupId): void
    {
        if ($groupId === null) return;
        
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM contacts WHERE group_id = ?");
        $stmt->execute([$groupId]);
        $count = $stmt->fetchColumn();
        
        $stmt = $this->db->prepare("UPDATE contact_groups SET contact_count = ? WHERE id = ?");
        $stmt->execute([$count, $groupId]);
    }

    public function getGroupStats(): array
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM contact_groups");
        $stmt->execute();
        $total = $stmt->fetch()['total'];

        $stmt = $this->db->prepare("SELECT SUM(contact_count) as total_contacts FROM contact_groups");
        $stmt->execute();
        $contacts = $stmt->fetch()['total_contacts'] ?? 0;

        return [
            'total_groups' => (int)$total,
            'total_contacts' => (int)$contacts
        ];
    }
}
