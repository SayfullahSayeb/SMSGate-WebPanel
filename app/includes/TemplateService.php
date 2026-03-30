<?php
/**
 * Template Service - Message template operations
 */

declare(strict_types=1);

class TemplateService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function getAll(array $filters = []): array
    {
        $sql = "SELECT * FROM templates WHERE 1=1";
        $params = [];

        if (!empty($filters['category'])) {
            $sql .= " AND category = ?";
            $params[] = $filters['category'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (name LIKE ? OR content LIKE ?)";
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
        }

        $sql .= " ORDER BY usage_count DESC, updated_at DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int)$filters['limit'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM templates WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function create(array $data): array
    {
        $variables = $this->extractVariables($data['content'] ?? '');
        
        $stmt = $this->db->prepare("
            INSERT INTO templates (name, subject, content, variables, category)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $data['name'],
            $data['subject'] ?? null,
            $data['content'],
            json_encode($variables),
            $data['category'] ?? 'general'
        ]);

        if ($result) {
            return ['success' => true, 'id' => $this->db->lastInsertId()];
        }

        return ['success' => false, 'error' => 'Failed to create template'];
    }

    public function update(int $id, array $data): array
    {
        $variables = $this->extractVariables($data['content'] ?? '');
        
        $stmt = $this->db->prepare("
            UPDATE templates SET 
                name = ?, 
                subject = ?, 
                content = ?, 
                variables = ?,
                category = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        
        $result = $stmt->execute([
            $data['name'],
            $data['subject'] ?? null,
            $data['content'],
            json_encode($variables),
            $data['category'] ?? 'general',
            $id
        ]);

        if ($result) {
            return ['success' => true];
        }

        return ['success' => false, 'error' => 'Failed to update template'];
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM templates WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function incrementUsage(int $id): void
    {
        $stmt = $this->db->prepare("UPDATE templates SET usage_count = usage_count + 1 WHERE id = ?");
        $stmt->execute([$id]);
    }

    public function extractVariables(string $content): array
    {
        preg_match_all('/\{\{(\w+)\}\}/', $content, $matches);
        return array_unique($matches[1] ?? []);
    }

    public function preview(string $content, array $variables): string
    {
        $preview = $content;
        foreach ($variables as $key => $value) {
            $preview = str_replace('{{' . $key . '}}', $value ?: '[' . $key . ']', $preview);
        }
        return $preview;
    }

    public function getCategories(): array
    {
        $stmt = $this->db->prepare("SELECT DISTINCT category FROM templates ORDER BY category");
        $stmt->execute();
        return array_column($stmt->fetchAll(), 'category');
    }

    public function getStats(): array
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM templates");
        $stmt->execute();
        $total = $stmt->fetch()['total'];

        $stmt = $this->db->prepare("SELECT SUM(usage_count) as total_usage FROM templates");
        $stmt->execute();
        $usage = $stmt->fetch()['total_usage'] ?? 0;

        return [
            'total_templates' => (int)$total,
            'total_usage' => (int)$usage
        ];
    }
}
