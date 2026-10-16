<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\controller\EnterpriseApi;
use app\common\supplier\ChannelAvailabilityService;
use app\common\supplier\MediaSyncJob;
use app\common\supplier\MediaSyncService;
use app\common\supplier\SupplierAdapterFactory;
use PDO;
use think\Exception;
use app\common\supplier\MediaPricingService;

class Media extends EnterpriseApi
{
    protected $noNeedLogin = [];
    protected $noNeedRight = [];

    private const FILTER_COLUMNS_VERSION = 3;
    private const CATALOG_FILTER_FIELDS = [
        '/media/type/1' => ['portal_type', 'channel_type', 'region', 'inclusion', 'draft_speed', 'media_category', 'contact_type', 'distribution_zone', 'ai_inclusion'],
        '/media/type/3' => ['platform', 'industry', 'region', 'inclusion', 'fans_range', 'read_range', 'media_category', 'verification', 'distribution_zone'],
        '/video' => ['platform', 'occupation', 'region', 'fans_range', 'special_projects', 'gender'],
    ];

    private PDO $pdo;
    private string $prefix;
    private MediaPricingService $pricing;
    private array $filterColumns = [];

    public function _initialize()
    {
        parent::_initialize();
        \think\Db::query('SELECT 1');
        $this->pdo = \think\Db::connect()->getConnection()->getPdo();
        $this->prefix = \think\Db::getConfig('prefix');
        $this->pricing = new MediaPricingService();
        $this->filterColumns = $this->detectFilterColumns();
    }

    /**
     * Enterprise media list with filtering.
     * GET /api/media/index
     */
    public function index()
    {
        $context = $this->context();
        $companyId = $context->companyId();

        $channelId = $this->request->param('channel_id', 0, 'intval');
        $platform = trim((string) $this->request->param('platform', ''));
        $status = trim((string) $this->request->param('status', ''));
        $keyword = trim((string) $this->request->param('keyword', ''));
        $sourceCatalog = trim((string) $this->request->param('source_catalog', ''));
        $region = trim((string) $this->request->param('region', ''));
        $mediaCategory = trim((string) $this->request->param('media_category', ''));
        $portalType = trim((string) $this->request->param('portal_type', ''));
        $channelType = trim((string) $this->request->param('channel_type', ''));
        $inclusion = trim((string) $this->request->param('inclusion', ''));
        $draftSpeed = trim((string) $this->request->param('draft_speed', ''));
        $industry = trim((string) $this->request->param('industry', ''));
        $fansRange = trim((string) $this->request->param('fans_range', ''));
        $readRange = trim((string) $this->request->param('read_range', ''));
        $occupation = trim((string) $this->request->param('occupation', ''));
        $specialProjects = trim((string) $this->request->param('special_projects', ''));
        $verification = trim((string) $this->request->param('verification', ''));
        $distributionZone = trim((string) $this->request->param('distribution_zone', ''));
        $contactType = trim((string) $this->request->param('contact_type', ''));
        $aiInclusion = trim((string) $this->request->param('ai_inclusion', ''));
        $gender = trim((string) $this->request->param('gender', ''));
        $isCollect = trim((string) $this->request->param('is_collect', ''));
        $remark = trim((string) $this->request->param('remark', ''));
        $specialTag = trim((string) $this->request->param('special_tag', ''));
        $exact = trim((string) $this->request->param('exact', '')) === '1';
        $startPrice = (float) $this->request->param('start_price', 0.0, 'floatval');
        $endPrice = (float) $this->request->param('end_price', 0.0, 'floatval');
        $page = max(1, $this->request->param('page', 1, 'intval'));
        $perPage = min(max(1, $this->request->param('per_page', 20, 'intval')), 100);

        if (!in_array($status, ['', 'active', 'offline'], true)) {
            $this->error('Invalid status filter.', null, 400);
        }
        if ($startPrice < 0 || $endPrice < 0 || ($startPrice > 0 && $endPrice > 0 && $startPrice > $endPrice)) {
            $this->error('Invalid price filter.', null, 400);
        }

        // Only show sellable media (active, not manually offline)
        $where = "WHERE mr.status = 'active'";
        $params = [];

        if ($channelId > 0) {
            $where .= ' AND mr.channel_id = :cid';
            $params['cid'] = $channelId;
        }
        if ($exact && $keyword !== '') {
            $where .= ' AND mr.name = :keyword';
            $params['keyword'] = $keyword;
        } elseif ($keyword !== '') {
            $linkColumn = $this->filterColumns['media_link'] ?? null;
            if ($linkColumn !== null) {
                $where .= ' AND (mr.name LIKE :keyword OR mr.`' . $linkColumn . '` LIKE :keyword_link)';
                $params['keyword_link'] = '%' . $keyword . '%';
            } else {
                $where .= ' AND mr.name LIKE :keyword';
            }
            $params['keyword'] = '%' . $keyword . '%';
        }
        if ($platform !== '') {
            $where .= ' AND JSON_UNQUOTE(JSON_EXTRACT(mr.attributes_json, \'$.platform\')) = :platform';
            $params['platform'] = $platform;
        }
        if ($remark !== '') {
            $remarkColumn = $this->filterColumns['remark'] ?? null;
            if ($remarkColumn !== null) {
                $where .= ' AND mr.`' . $remarkColumn . '` LIKE :remark';
            } else {
                $where .= " AND JSON_UNQUOTE(JSON_EXTRACT(mr.attributes_json, '$.remark')) LIKE :remark";
            }
            $params['remark'] = '%' . $remark . '%';
        }
        if ($specialTag !== '') {
            $mcColumn = $this->filterColumns['media_category'] ?? null;
            $spColumn = $this->filterColumns['special_projects'] ?? null;
            if ($mcColumn !== null && $spColumn !== null) {
                $where .= ' AND (mr.`' . $mcColumn . '` = :special_tag_mc OR mr.`' . $spColumn . '` = :special_tag_sp)';
                $params['special_tag_mc'] = $specialTag;
                $params['special_tag_sp'] = $specialTag;
            } elseif ($mcColumn !== null) {
                $where .= ' AND mr.`' . $mcColumn . '` = :special_tag';
            } elseif ($spColumn !== null) {
                $where .= ' AND mr.`' . $spColumn . '` = :special_tag';
            } else {
                $where .= " AND (JSON_UNQUOTE(JSON_EXTRACT(mr.attributes_json, '$.media_category')) = :special_tag_mc"
                    . " OR JSON_UNQUOTE(JSON_EXTRACT(mr.attributes_json, '$.special_projects')) = :special_tag_sp)";
                $params['special_tag_mc'] = $specialTag;
                $params['special_tag_sp'] = $specialTag;
            }
        }
        foreach ([
            'source_catalog' => $sourceCatalog,
            'region' => $region,
            'media_category' => $mediaCategory,
            'portal_type' => $portalType,
            'channel_type' => $channelType,
            'inclusion' => $inclusion,
            'draft_speed' => $draftSpeed,
            'industry' => $industry,
            'fans_range' => $fansRange,
            'read_range' => $readRange,
            'occupation' => $occupation,
            'special_projects' => $specialProjects,
            'verification' => $verification,
            'distribution_zone' => $distributionZone,
            'contact_type' => $contactType,
            'ai_inclusion' => $aiInclusion,
            'gender' => $gender,
        ] as $field => $value) {
            if ($value === '') {
                continue;
            }
            if (strlen($value) > 191) {
                $this->error('Invalid media filter.', null, 400);
            }
            $where .= ' AND ' . $this->attributeFilterSql($field, $field);
            $params[$field] = $value;
        }
        if ($isCollect === '1') {
            $favoriteTable = $this->quoteTable('media_favorites');
            $where .= ' AND EXISTS (SELECT 1 FROM ' . $favoriteTable . ' mf'
                . ' WHERE mf.media_resource_id = mr.id AND mf.company_id = :favorite_company)';
            $params['favorite_company'] = $companyId;
        } elseif ($isCollect !== '') {
            $this->error('Invalid collect filter.', null, 400);
        }
        if ($startPrice > 0) {
            $where .= ' AND CEIL(mr.cost_price * :markup_start * 100) / 100 >= :start_price';
            $params['start_price'] = $startPrice;
            $params['markup_start'] = $this->pricing->markupRate();
        }
        if ($endPrice > 0) {
            $where .= ' AND CEIL(mr.cost_price * :markup_end * 100) / 100 <= :end_price';
            $params['end_price'] = $endPrice;
            $params['markup_end'] = $this->pricing->markupRate();
        }

        // Count
        $countSql = 'SELECT COUNT(*) FROM ' . $this->quoteTable('media_resources') . ' mr ' . $where;
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Fetch page
        $offset = ($page - 1) * $perPage;
        $dataSql = 'SELECT mr.id, mr.supplier_id, mr.external_id, mr.channel_id, mr.name, mr.status,'
            . ' mr.attributes_json, mr.last_synced_at, mr.cost_price, c.code AS channel_code,'
            . ' c.name AS channel_name'
            . ' FROM ' . $this->quoteTable('media_resources') . ' mr'
            . ' LEFT JOIN ' . $this->quoteTable('channels') . ' c ON c.id = mr.channel_id'
            . ' ' . $where
            . ' ORDER BY mr.id DESC'
            . ' LIMIT ' . $offset . ', ' . $perPage;

        $stmt = $this->pdo->prepare($dataSql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $favoriteIds = $this->companyFavoriteIds($companyId, array_map(static function (array $row): int {
            return (int) $row['id'];
        }, $rows));
        $supplierId = $this->getMediaMarketSupplierId();
        $lastSync = $this->getLastSyncTime($supplierId);
        $channelAvailability = new ChannelAvailabilityService($this->pdo, $this->prefix);
        $channels = $channelAvailability->channels();
        $confirmedChannelIds = $channelAvailability->confirmedChannelIds();
        $channelGroupsKey = (string) $lastSync . '|' . implode(',', array_map(static function (array $channel): string {
            return $channel['id'] . ':' . (int) ($channel['supplier_confirmed'] ?? 0);
        }, $channels));

        $items = [];
        foreach ($rows as $row) {
            $attrs = json_decode((string) ($row['attributes_json'] ?? '{}'), true) ?: [];
            $channelId = (int) ($row['channel_id'] ?? 0);
            $priceUnconfirmed = (bool) (
                $attrs['price_unconfirmed'] ?? ($supplierId !== null && (int) ($row['supplier_id'] ?? 0) === $supplierId)
            );
            $salePrice = $priceUnconfirmed ? null : $this->pricing->calculateSalePrice($row['cost_price']);
            $items[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'channel_code' => $row['channel_code'] ?? '',
                'channel_name' => $row['channel_name'] ?? '',
                'status' => $row['status'],
                'sale_price' => $salePrice,
                'price_unconfirmed' => $priceUnconfirmed,
                'platform' => $attrs['platform'] ?? null,
                'source_catalog' => $attrs['source_catalog'] ?? null,
                'region' => $attrs['region'] ?? null,
                'media_category' => $attrs['media_category'] ?? null,
                'portal_type' => $attrs['portal_type'] ?? null,
                'channel_type' => $attrs['channel_type'] ?? null,
                'inclusion' => $attrs['inclusion'] ?? null,
                'draft_speed' => $attrs['draft_speed'] ?? null,
                'industry' => $attrs['industry'] ?? null,
                'fans_range' => $attrs['fans_range'] ?? null,
                'read_range' => $attrs['read_range'] ?? null,
                'occupation' => $attrs['occupation'] ?? null,
                'special_projects' => $attrs['special_projects'] ?? null,
                'verification' => $attrs['verification'] ?? null,
                'distribution_zone' => $attrs['distribution_zone'] ?? null,
                'contact_type' => $attrs['contact_type'] ?? null,
                'ai_inclusion' => $attrs['ai_inclusion'] ?? null,
                'gender' => $attrs['gender'] ?? null,
                'is_collect' => in_array((int) $row['id'], $favoriteIds, true) ? 1 : 0,
                'supplier_is_collect' => $attrs['is_collect'] ?? null,
                'remark' => $attrs['remark'] ?? null,
                'media_link' => $attrs['media_link'] ?? null,
                'read_num' => $attrs['read_num'] ?? null,
                'read_num_str' => $attrs['read_num_str'] ?? null,
                'live_market_price' => $attrs['live_market_price'] ?? null,
                'short_market_price' => $attrs['short_market_price'] ?? null,
                'is_recommend' => $attrs['is_recommend'] ?? null,
                'is_preferred' => $attrs['is_preferred'] ?? null,
                'is_top' => $attrs['is_top'] ?? null,
                'is_discount' => $attrs['is_discount'] ?? null,
                'weighting' => $attrs['weighting'] ?? null,
                'manuscript_rate' => $attrs['manuscript_rate'] ?? null,
                'fans_num' => $attrs['fans_num'] ?? null,
                'sales_volume' => $attrs['sales_volume'] ?? null,
                'can_order' => !$priceUnconfirmed && isset($confirmedChannelIds[$channelId]),
                'last_synced_at' => $row['last_synced_at'],
            ];
        }

        $this->success('', [
            'items' => $items,
            'channels' => $channels,
            'channel_groups' => $this->channelGroups($channels, $channelGroupsKey),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_sync_time' => $lastSync,
        ]);
    }

    /**
     * Enterprise media filter options.
     * GET /api/media/filters
     */
    public function filters()
    {
        $this->context();
        $supplierId = $this->getMediaMarketSupplierId();
        $lastSync = $this->getLastSyncTime($supplierId);
        $cacheKey = (string) $lastSync;
        $cache = $this->readCache();
        if (isset($cache['options'][$cacheKey]) && is_array($cache['options'][$cacheKey])) {
            $options = $cache['options'][$cacheKey];
        } else {
            $options = $this->filterOptions();
            $cache['options'][$cacheKey] = $options;
            $this->writeCache($cache);
        }
        if (isset($cache['catalog_options'][$cacheKey]) && is_array($cache['catalog_options'][$cacheKey])) {
            $byCatalog = $cache['catalog_options'][$cacheKey];
        } else {
            $byCatalog = $this->filterOptionsByCatalog();
            $cache['catalog_options'][$cacheKey] = $byCatalog;
            $this->writeCache($cache);
        }
        $this->success('', ['options' => $options, 'by_catalog' => $byCatalog, 'last_sync_time' => $lastSync]);
    }

    /**
     * Enterprise media detail.
     * GET /api/media/detail
     */
    public function detail()
    {
        $context = $this->context();
        $companyId = $context->companyId();

        $id = $this->request->param('id', 0, 'intval');
        if ($id <= 0) {
            $this->error('Invalid media ID.', null, 400);
        }

        $stmt = $this->pdo->prepare(
            'SELECT mr.*, c.code AS channel_code, c.name AS channel_name'
            . ' FROM ' . $this->quoteTable('media_resources') . ' mr'
            . ' LEFT JOIN ' . $this->quoteTable('channels') . ' c ON c.id = mr.channel_id'
            . ' WHERE mr.id = ? AND mr.status = ?'
        );
        $stmt->execute([$id, 'active']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $this->error('Media not found.', null, 404);
        }

        $attrs = json_decode((string) ($row['attributes_json'] ?? '{}'), true) ?: [];
        $priceUnconfirmed = (bool) (
            $attrs['price_unconfirmed'] ?? ($this->getMediaMarketSupplierId() !== null
                && (int) ($row['supplier_id'] ?? 0) === $this->getMediaMarketSupplierId())
        );
        $salePrice = $priceUnconfirmed ? null : $this->pricing->calculateSalePrice($row['cost_price']);
        $channelAvailability = new ChannelAvailabilityService($this->pdo, $this->prefix);
        $confirmedChannelIds = $channelAvailability->confirmedChannelIds();
        $channelId = (int) ($row['channel_id'] ?? 0);
        $isCollected = $this->isFavorite($companyId, (int) $row['id']);

        $this->success('', [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'channel_code' => $row['channel_code'] ?? '',
            'channel_name' => $row['channel_name'] ?? '',
            'status' => $row['status'],
            'sale_price' => $salePrice,
            'price_unconfirmed' => $priceUnconfirmed,
            'region' => $attrs['region'] ?? null,
            'media_category' => $attrs['media_category'] ?? null,
            'portal_type' => $attrs['portal_type'] ?? null,
            'channel_type' => $attrs['channel_type'] ?? null,
            'inclusion' => $attrs['inclusion'] ?? null,
            'draft_speed' => $attrs['draft_speed'] ?? null,
            'industry' => $attrs['industry'] ?? null,
            'occupation' => $attrs['occupation'] ?? null,
            'special_projects' => $attrs['special_projects'] ?? null,
            'verification' => $attrs['verification'] ?? null,
            'distribution_zone' => $attrs['distribution_zone'] ?? null,
            'contact_type' => $attrs['contact_type'] ?? null,
            'ai_inclusion' => $attrs['ai_inclusion'] ?? null,
            'gender' => $attrs['gender'] ?? null,
            'is_collect' => $isCollected ? 1 : 0,
            'supplier_is_collect' => $attrs['is_collect'] ?? null,
            'remark' => $attrs['remark'] ?? null,
            'media_link' => $attrs['media_link'] ?? null,
            'read_num' => $attrs['read_num'] ?? null,
            'read_num_str' => $attrs['read_num_str'] ?? null,
            'is_recommend' => $attrs['is_recommend'] ?? null,
            'is_preferred' => $attrs['is_preferred'] ?? null,
            'is_top' => $attrs['is_top'] ?? null,
            'is_discount' => $attrs['is_discount'] ?? null,
            'price_range' => $attrs['price_range'] ?? null,
            'fans_range' => $attrs['fans_range'] ?? null,
            'read_range' => $attrs['read_range'] ?? null,
            'weighting' => $attrs['weighting'] ?? null,
            'fans_num' => $attrs['fans_num'] ?? null,
            'sales_volume' => $attrs['sales_volume'] ?? null,
            'manuscript_rate' => $attrs['manuscript_rate'] ?? null,
            'platform' => $attrs['platform'] ?? null,
            'source_catalog' => $attrs['source_catalog'] ?? null,
            'short_video_capability' => $attrs['short_video_capability'] ?? null,
            'live_capability' => $attrs['live_capability'] ?? null,
            'short_market_price' => $attrs['short_market_price'] ?? null,
            'live_market_price' => $attrs['live_market_price'] ?? null,
            'can_order' => !$priceUnconfirmed && isset($confirmedChannelIds[$channelId]),
            'last_synced_at' => $row['last_synced_at'],
        ]);
    }

    private function cachePath(): string
    {
        $runtime = ROOT_PATH . 'runtime' . DIRECTORY_SEPARATOR;
        if (!is_dir($runtime)) {
            @mkdir($runtime, 0775, true);
        }
        return $runtime . 'media_catalog_filter_cache_' . md5($this->prefix) . '.php';
    }

    private function readCache(): array
    {
        $path = $this->cachePath();
        if (!is_file($path)) {
            return [];
        }
        $value = @include $path;
        return is_array($value) ? $value : [];
    }

    private function writeCache(array $data): void
    {
        $path = $this->cachePath();
        @file_put_contents($path, "<?php\nreturn " . var_export($data, true) . ";\n", LOCK_EX);
    }

    private function detectFilterColumns(): array
    {
        $cache = $this->readCache();
        if (
            isset($cache['columns_version']) && $cache['columns_version'] === self::FILTER_COLUMNS_VERSION
            && isset($cache['columns']) && is_array($cache['columns'])
        ) {
            return $cache['columns'];
        }
        $fields = [
            'source_catalog',
            'platform',
            'region',
            'media_category',
            'portal_type',
            'channel_type',
            'inclusion',
            'draft_speed',
            'industry',
            'fans_range',
            'read_range',
            'occupation',
            'special_projects',
            'remark',
            'verification',
            'distribution_zone',
            'contact_type',
            'ai_inclusion',
            'gender',
            'is_collect',
            'media_link',
        ];
        $table = $this->prefix . 'media_resources';
        $stmt = $this->pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table AND COLUMN_NAME LIKE :like'
        );
        $stmt->execute(['table' => $table, 'like' => 'filter\_%']);
        $existing = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
        $result = [];
        foreach ($fields as $field) {
            $column = 'filter_' . $field;
            $result[$field] = isset($existing[$column]) ? $column : null;
        }
        $cache['columns'] = $result;
        $cache['columns_version'] = self::FILTER_COLUMNS_VERSION;
        $this->writeCache($cache);
        return $result;
    }

    private function filterOptionsByCatalog(): array
    {
        $result = [];
        foreach (self::CATALOG_FILTER_FIELDS as $catalog => $keys) {
            $result[$catalog] = $this->filterOptionsForCatalog($catalog, $keys);
        }
        return $result;
    }

    private function filterOptionsForCatalog(string $catalog, array $keys): array
    {
        foreach ($keys as $key) {
            if (($this->filterColumns[$key] ?? null) === null) {
                return $this->filterOptionsFromJsonForCatalog($catalog, $keys);
            }
        }
        return $this->filterOptionsFromGroupedForCatalog($catalog, $keys);
    }

    private function filterOptionsFromGroupedForCatalog(string $catalog, array $keys): array
    {
        $sourceColumn = $this->filterColumns['source_catalog'];
        $result = [];
        foreach ($keys as $key) {
            $column = $this->filterColumns[$key];
            $stmt = $this->pdo->prepare(
                'SELECT mr.`' . $column . '` AS value, COUNT(*) AS c'
                . ' FROM ' . $this->quoteTable('media_resources') . ' mr'
                . ' WHERE mr.status = :status AND mr.`' . $sourceColumn . '` = :catalog'
                . ' AND mr.`' . $column . '` IS NOT NULL AND mr.`' . $column . '` <> :empty'
                . ' GROUP BY mr.`' . $column . '` ORDER BY c DESC, value LIMIT 500'
            );
            $stmt->execute(['status' => 'active', 'catalog' => $catalog, 'empty' => '']);
            $items = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $items[] = ['value' => (string) $row['value'], 'count' => (int) $row['c']];
            }
            $result[$key] = $items;
        }
        return $result;
    }

    private function filterOptionsFromJsonForCatalog(string $catalog, array $keys): array
    {
        $counts = [];
        $lastId = 0;
        $stmt = $this->pdo->prepare(
            'SELECT id, attributes_json FROM ' . $this->quoteTable('media_resources')
            . ' WHERE status = :status AND JSON_UNQUOTE(JSON_EXTRACT(attributes_json, \'$.source_catalog\')) = :catalog'
            . ' AND id > :last_id ORDER BY id LIMIT 5000'
        );
        while (true) {
            $stmt->execute(['status' => 'active', 'catalog' => $catalog, 'last_id' => $lastId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                $attrs = json_decode((string) ($row['attributes_json'] ?? '{}'), true);
                if (!is_array($attrs)) {
                    continue;
                }
                foreach ($keys as $key) {
                    $value = $attrs[$key] ?? null;
                    if ($value === null || $value === '' || (is_array($value) && $value === [])) {
                        continue;
                    }
                    if (is_array($value)) {
                        $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                    $counts[$key][(string) $value] = ($counts[$key][(string) $value] ?? 0) + 1;
                }
            }
            if (count($rows) < 5000) {
                break;
            }
        }
        return $this->buildFilterOptions($keys, $counts);
    }

    private function attributeFilterSql(string $field, string $placeholder): string
    {
        $column = $this->filterColumns[$field] ?? null;
        if ($column !== null) {
            return 'mr.`' . $column . '` = :' . $placeholder;
        }
        return "JSON_UNQUOTE(JSON_EXTRACT(mr.attributes_json, '$." . $field . "')) = :" . $placeholder;
    }

    private function filterOptions(): array
    {
        $keys = [
            'source_catalog',
            'region',
            'media_category',
            'portal_type',
            'channel_type',
            'inclusion',
            'draft_speed',
            'industry',
            'platform',
        ];
        $allColumns = true;
        foreach ($keys as $key) {
            if (($this->filterColumns[$key] ?? null) === null) {
                $allColumns = false;
                break;
            }
        }
        return $allColumns ? $this->filterOptionsFromGrouped($keys) : $this->filterOptionsFromJson($keys);
    }

    private function filterOptionsFromJson(array $keys): array
    {
        $counts = [];
        $lastId = 0;
        $stmt = $this->pdo->prepare(
            'SELECT id, attributes_json FROM ' . $this->quoteTable('media_resources')
            . ' WHERE status = :status AND id > :last_id ORDER BY id LIMIT 5000'
        );
        while (true) {
            $stmt->execute(['status' => 'active', 'last_id' => $lastId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                $attrs = json_decode((string) ($row['attributes_json'] ?? '{}'), true);
                if (!is_array($attrs)) {
                    continue;
                }
                foreach ($keys as $key) {
                    $value = $attrs[$key] ?? null;
                    if ($value === null || $value === '' || (is_array($value) && $value === [])) {
                        continue;
                    }
                    if (is_array($value)) {
                        $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                    $counts[$key][(string) $value] = ($counts[$key][(string) $value] ?? 0) + 1;
                }
            }
            if (count($rows) < 5000) {
                break;
            }
        }
        return $this->buildFilterOptions($keys, $counts);
    }

    private function filterOptionsFromGrouped(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $column = $this->filterColumns[$key];
            $stmt = $this->pdo->prepare(
                'SELECT mr.`' . $column . '` AS value, COUNT(*) AS c'
                . ' FROM ' . $this->quoteTable('media_resources') . ' mr'
                . ' WHERE mr.status = :status AND mr.`' . $column . '` IS NOT NULL AND mr.`' . $column . '` <> :empty'
                . ' GROUP BY mr.`' . $column . '` ORDER BY c DESC, value LIMIT 500'
            );
            $stmt->execute(['status' => 'active', 'empty' => '']);
            $items = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $items[] = ['value' => (string) $row['value'], 'count' => (int) $row['c']];
            }
            $result[$key] = $items;
        }
        return $result;
    }

    private function buildFilterOptions(array $keys, array $counts): array
    {
        $result = [];
        foreach ($keys as $key) {
            $values = $counts[$key] ?? [];
            arsort($values, SORT_NUMERIC);
            $items = [];
            foreach ($values as $value => $count) {
                $items[] = ['value' => (string) $value, 'count' => $count];
            }
            $result[$key] = $items;
        }
        return $result;
    }

    private function channelGroups(array $channels, ?string $cacheKey = null): array
    {
        $cache = $this->readCache();
        if ($cacheKey !== null && isset($cache['channel_groups'][$cacheKey]) && is_array($cache['channel_groups'][$cacheKey])) {
            return $cache['channel_groups'][$cacheKey];
        }
        $groups = [];
        $platformExpr = $this->filterColumns['platform'] ?? null;
        $platformSql = $platformExpr !== null
            ? 'mr.`' . $platformExpr . '`'
            : "JSON_UNQUOTE(JSON_EXTRACT(mr.attributes_json, '$.platform'))";
        foreach ($channels as $channel) {
            $group = [
                'code' => $channel['code'] ?? '',
                'name' => $channel['name'] ?? '',
                'platforms' => [],
            ];
            if (($channel['code'] ?? '') === 'influencer') {
                $stmt = $this->pdo->prepare(
                    'SELECT DISTINCT ' . $platformSql . ' AS platform'
                    . ' FROM ' . $this->quoteTable('media_resources') . ' mr'
                    . ' LEFT JOIN ' . $this->quoteTable('channels') . ' c ON c.id = mr.channel_id'
                    . ' WHERE c.code = :code AND mr.status = :status'
                    . ' AND ' . $platformSql . ' IS NOT NULL'
                    . ' AND ' . $platformSql . ' <> :empty'
                    . ' ORDER BY platform'
                );
                $stmt->execute([
                    'code' => 'influencer',
                    'status' => 'active',
                    'empty' => '',
                ]);
                $group['platforms'] = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            }
            $groups[] = $group;
        }
        if ($cacheKey !== null) {
            $cache['channel_groups'][$cacheKey] = $groups;
            $this->writeCache($cache);
        }
        return $groups;
    }

    private function getMediaMarketSupplierId(): ?int
    {
        static $id = false;
        if ($id !== false) return $id;
        $stmt = $this->pdo->prepare(
            'SELECT id FROM ' . $this->quoteTable('suppliers') . ' WHERE code = ? AND status = ?'
        );
        $stmt->execute(['media_market', 'normal']);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    private function getLastSyncTime(?int $supplierId): ?string
    {
        if ($supplierId === null) {
            return null;
        }
        $cache = $this->readCache();
        $key = 'last_sync_' . $supplierId;
        if (isset($cache[$key]) && is_string($cache[$key])) {
            return $cache[$key];
        }
        $stmt = $this->pdo->prepare(
            'SELECT MAX(last_synced_at) FROM ' . $this->quoteTable('media_resources')
            . ' WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $result = $stmt->fetchColumn();
        $result = $result !== null && $result !== false ? (string) $result : null;
        $cache[$key] = $result;
        $this->writeCache($cache);
        return $result;
    }

    /**
     * Enterprise media favorite.
     * POST /api/media/favorite {media_id}
     */
    public function favorite()
    {
        $context = $this->context();
        $companyId = $context->companyId();
        $mediaId = (int) $this->request->post('media_id', 0, 'intval');
        if ($mediaId <= 0) {
            $this->error('Invalid media ID.', null, 400);
        }
        if (!$this->favoriteTableExists()) {
            $this->error('收藏功能尚未初始化。', null, 500);
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, JSON_UNQUOTE(JSON_EXTRACT(attributes_json, \'$.source_catalog\')) AS source_catalog'
            . ' FROM ' . $this->quoteTable('media_resources')
            . ' WHERE id = ? AND status = ?'
        );
        $stmt->execute([$mediaId, 'active']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->error('Media not found.', null, 404);
        }
        $sourceCatalog = $row['source_catalog'] !== null && $row['source_catalog'] !== false
            ? (string) $row['source_catalog']
            : '';
        $insert = $this->pdo->prepare(
            'INSERT INTO ' . $this->quoteTable('media_favorites')
            . ' (`company_id`,`media_resource_id`,`source_catalog`,`created_at`,`updated_at`)'
            . ' VALUES (:company_id,:media_id,:source_catalog,NOW(),NOW())'
            . ' ON DUPLICATE KEY UPDATE `updated_at` = NOW()'
        );
        $insert->execute([
            'company_id' => $companyId,
            'media_id' => $mediaId,
            'source_catalog' => $sourceCatalog,
        ]);
        $this->success('', ['is_collect' => true]);
    }

    /**
     * Enterprise media unfavorite.
     * POST /api/media/unfavorite {media_id}
     */
    public function unfavorite()
    {
        $context = $this->context();
        $companyId = $context->companyId();
        $mediaId = (int) $this->request->post('media_id', 0, 'intval');
        if ($mediaId <= 0) {
            $this->error('Invalid media ID.', null, 400);
        }
        if ($this->favoriteTableExists()) {
            $delete = $this->pdo->prepare(
                'DELETE FROM ' . $this->quoteTable('media_favorites')
                . ' WHERE `company_id` = :company_id AND `media_resource_id` = :media_id'
            );
            $delete->execute(['company_id' => $companyId, 'media_id' => $mediaId]);
        }
        $this->success('', ['is_collect' => false]);
    }

    private function companyFavoriteIds(int $companyId, array $mediaIds): array
    {
        if (!$this->favoriteTableExists()) {
            return [];
        }
        $mediaIds = array_values(array_unique(array_filter($mediaIds, static function ($id): bool {
            return $id > 0;
        })));
        if ($mediaIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($mediaIds), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT `media_resource_id` FROM ' . $this->quoteTable('media_favorites')
            . ' WHERE `company_id` = ? AND `media_resource_id` IN (' . $placeholders . ')'
        );
        $stmt->execute(array_merge([$companyId], $mediaIds));
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function isFavorite(int $companyId, int $mediaId): bool
    {
        if (!$this->favoriteTableExists()) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ' . $this->quoteTable('media_favorites')
            . ' WHERE `company_id` = ? AND `media_resource_id` = ?'
        );
        $stmt->execute([$companyId, $mediaId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function favoriteTableExists(): bool
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $stmt->execute(['table' => $this->prefix . 'media_favorites']);
        $exists = (int) $stmt->fetchColumn() > 0;
        return $exists;
    }

    private function quoteTable(string $name): string
    {
        return '`' . $this->prefix . $name . '`';
    }
}
