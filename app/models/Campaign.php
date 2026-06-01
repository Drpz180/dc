<?php
// app/models/Campaign.php
require_once __DIR__ . '/../Database.php';

class Campaign
{
    public static function findBySlug(string $slug): ?array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE slug = :slug AND is_active = TRUE");
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findAll(): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT * FROM campaigns ORDER BY created_at DESC");
        return $stmt->fetchAll();
    }

    public static function findById(int $id): ?array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function save(array $data): int {
        $pdo = Database::getConnection();
        $existingMinAmount = null;
        if (!array_key_exists('min_amount', $data) && !empty($data['id'])) {
            $existing = self::findById((int)$data['id']);
            if ($existing && isset($existing['min_amount'])) {
                $existingMinAmount = $existing['min_amount'];
            }
        }

        if (!empty($data['id'])) {
            $sql = "UPDATE campaigns SET
                        title = :title,
                        subtitle = :subtitle,
                        slug = :slug,
                        category = :category,
                        city = :city,
                        state = :state,
                        min_amount = :min_amount,
                        goal_amount = :goal_amount,
                        raised_amount = :raised_amount,
                        pix_key = :pix_key,
                        pix_description = :pix_description,
                        facebook_pixel_id = :facebook_pixel_id,
                        facebook_access_token = :facebook_access_token,
                        tiktok_pixel_id = :tiktok_pixel_id,
                        tiktok_access_token = :tiktok_access_token,
                        utmify_api_token = :utmify_api_token,
                        cover_image = :cover_image,
                        description = :description,
                        hearts_received = :hearts_received,
                        supporters = :supporters,
                        is_active = :is_active
                    WHERE id = :id";
        } else {
            $sql = "INSERT INTO campaigns
                        (title, subtitle, slug, category, city, state,
                         min_amount, goal_amount, raised_amount, pix_key, pix_description,
                         facebook_pixel_id, facebook_access_token, tiktok_pixel_id, tiktok_access_token, utmify_api_token,
                         cover_image, description, hearts_received, supporters, is_active)
                    VALUES
                        (:title, :subtitle, :slug, :category, :city, :state,
                         :min_amount, :goal_amount, :raised_amount, :pix_key, :pix_description,
                         :facebook_pixel_id, :facebook_access_token, :tiktok_pixel_id, :tiktok_access_token, :utmify_api_token,
                         :cover_image, :description, :hearts_received, :supporters, :is_active)";
        }

        $stmt = $pdo->prepare($sql);

        $params = [
            'title'                 => $data['title'],
            'subtitle'              => $data['subtitle'] ?? null,
            'slug'                  => $data['slug'],
            'category'              => $data['category'] ?? 'Saúde / Tratamentos',
            'city'                  => $data['city'] ?? null,
            'state'                 => $data['state'] ?? null,
            'min_amount'            => $data['min_amount'] ?? ($existingMinAmount ?? 25),
            'goal_amount'           => $data['goal_amount'] ?? 0,
            'raised_amount'         => $data['raised_amount'] ?? 0,
            'pix_key'               => $data['pix_key'] ?? null,
            'pix_description'       => $data['pix_description'] ?? null,
            'facebook_pixel_id'     => $data['facebook_pixel_id'] ?? null,
            'facebook_access_token' => $data['facebook_access_token'] ?? null,
            'tiktok_pixel_id'       => $data['tiktok_pixel_id'] ?? null,
            'tiktok_access_token'   => $data['tiktok_access_token'] ?? null,
            'utmify_api_token'      => $data['utmify_api_token'] ?? null,
            'cover_image'           => $data['cover_image'] ?? null,
            'description'           => $data['description'] ?? null,
            'hearts_received'       => isset($data['hearts_received']) ? (int)$data['hearts_received'] : 0,
            'supporters'            => isset($data['supporters']) ? (int)$data['supporters'] : 0,
            'is_active'             => !empty($data['is_active']),
        ];

        if (!empty($data['id'])) {
            $params['id'] = $data['id'];
        }

        $stmt->execute($params);

        return !empty($data['id']) ? $data['id'] : (int)$pdo->lastInsertId();
    }
}
