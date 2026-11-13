<?php
require_once __DIR__ . '/database.php';

/**
 * Decode the legacy single-photo value or the current JSON photo list without
 * importing the API bootstrap. This service is used by both HTML pages and
 * JSON endpoints, so it must not start sessions or emit HTTP headers.
 */
function dorm_catalog_photo_list(mixed $raw): array {
    $decoded = json_decode((string)$raw, true);
    if (is_array($decoded)) {
        return array_values(array_filter($decoded, 'is_string'));
    }

    return ($raw !== null && $raw !== false && $raw !== '') ? [(string)$raw] : [];
}

if (!function_exists('resolve_landlord_photo_url')) {
    function resolve_landlord_photo_url(?string $profilePhoto, ?string $googlePhoto): ?string {
        $url = resolve_user_profile_photo_url($profilePhoto, $googlePhoto, 'landlord');
        return $url !== '' ? $url : null;
    }
}

if (!function_exists('parse_landlord_social')) {
    function parse_landlord_social(?string $raw): array {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return ['facebook' => null, 'messenger' => null, 'username' => null];
        }

        $clean = preg_replace('~^https?://(?:www\.)?facebook\.com/~i', '', $raw);
        $clean = preg_replace('~^(?:www\.)?facebook\.com/~i', '', $clean);
        $clean = ltrim($clean, '/');

        $fbUrl = 'https://www.facebook.com/' . $clean;
        $messengerUrl = null;
        $username = null;

        if (preg_match('~m\.me/([^/?&#]+)~i', $raw, $m)) {
            $username = $m[1];
            $messengerUrl = 'https://m.me/' . $username;
        } elseif (preg_match('~profile\.php\?id=([0-9]+)~i', $raw, $m)) {
            $username = $m[1];
            $messengerUrl = 'https://m.me/' . $username;
        } elseif ($clean !== '') {
            $candidate = explode('/', explode('?', $clean)[0])[0] ?? '';
            if ($candidate !== '' && !in_array(strtolower($candidate), ['pages', 'groups', 'share', 'home', 'messages', 'watch', 'marketplace'])) {
                $username = $candidate;
                $messengerUrl = 'https://m.me/' . $username;
            }
        }

        if (!$messengerUrl && $fbUrl) {
            $messengerUrl = $fbUrl;
        }
        return [
            'facebook' => $fbUrl,
            'messenger' => $messengerUrl,
            'username' => $username,
        ];
    }
}

/**
 * Returns the fully structured dorms catalog for tenant views.
 */
function fetch_approved_dorms_catalog(PDO $pdo, int $currentUserId = 0): array {
    $sql = "SELECT p.*,
                   u.id AS landlord_user_id,
                   CONCAT_WS(' ', u.first_name, u.last_name) AS landlordName,
                   u.profile_photo AS landlordPhoto,
                   u.profile_photo AS landlordProfilePhoto,
                   u.phone_number AS landlordPhone,
                   u.facebook_link AS landlordFacebook,
                   u.email AS landlordEmail,
                   u.status AS landlordVerification,
                   u.created_at AS landlordCreatedAt
            FROM dorms p
            JOIN landlords u ON u.id = p.landlord_id
            WHERE p.approval_status = 'approved' AND u.status = 'approved'
            ORDER BY p.created_at DESC";
    $st = $pdo->query($sql);
    $props = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];

    // Fetch tenants per room and dorm categorized by status
    $occupantsByRoom = [];
    $occupantsByProp = [];
    $tenantStatsByProp = [];
    try {
        $occStmt = $pdo->query("
          SELECT r.dorm_id, b.room_id, b.tenant_id, b.status AS booking_status,
                 CONCAT_WS(' ', u.first_name, u.last_name) AS full_name,
                 u.first_name, u.last_name, u.university, u.email, u.profile_photo, u.profile_photo AS photo
          FROM bookings b
          JOIN rooms r ON r.id = b.room_id
          JOIN tenants u ON u.id = b.tenant_id
          WHERE b.status IN ('approved', 'pending', 'declined')
        ");
        if ($occStmt) {
            foreach ($occStmt->fetchAll(PDO::FETCH_ASSOC) as $occ) {
                $name = trim((string)$occ['full_name']);
                if (!$name && !empty($occ['email'])) {
                    $name = explode('@', $occ['email'])[0];
                }
                $rawStatus = (string)($occ['booking_status'] ?? 'pending');
                $category = ($rawStatus === 'approved') ? 'approved' : ($rawStatus === 'declined' ? 'declined' : 'pending');

                $occData = [
                    'userId' => (int)$occ['tenant_id'],
                    'name' => $name ?: 'Student Resident',
                    'school' => $occ['university'] ?: 'Student',
                    'university' => $occ['university'] ?: 'Student',
                    'photo' => resolve_user_profile_photo_url(
                        $occ['profile_photo'] ?? null,
                        $occ['photo'] ?? null,
                        'tenant'
                    ) ?: null,
                    'status' => $category,
                    'rawStatus' => $rawStatus,
                ];

                $propId = (int)$occ['dorm_id'];
                if (!isset($tenantStatsByProp[$propId])) {
                    $tenantStatsByProp[$propId] = ['approved' => 0, 'pending' => 0, 'declined' => 0, 'total' => 0];
                }
                $tenantStatsByProp[$propId][$category]++;
                $tenantStatsByProp[$propId]['total']++;

                if (!empty($occ['room_id'])) {
                    $occupantsByRoom[(int)$occ['room_id']][] = $occData;
                }
                $occupantsByProp[$propId][] = $occData;
            }
        }
    } catch (Throwable $e) {}

    $byProp = [];
    try {
        $rStmt = $pdo->query('SELECT * FROM rooms ORDER BY id');
        if ($rStmt) {
            foreach ($rStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $roomId = (int)$r['id'];
                $allRoomTenants = $occupantsByRoom[$roomId] ?? [];
                $activeOccupants = array_filter($allRoomTenants, fn($t) => $t['status'] === 'approved');
                $occVal = (string)($r['occupancy'] ?? '1');
                $occInt = max(1, (int)$occVal);
                $numActiveOccupants = count($activeOccupants);
                $totalRent = (float)($r['total_rent'] ?? 0);
                $divisor = $numActiveOccupants > 0 ? $numActiveOccupants : $occInt;
                $dynamicRentPerHead = $divisor > 0 ? round($totalRent / $divisor, 2) : $totalRent;

                $byProp[$r['dorm_id']][] = [
                    'id' => $roomId,
                    'name' => $r['name'],
                    'occupancy' => $occVal,
                    'type' => $occVal,
                    'capacity' => $occInt,
                    'total_rent' => $totalRent,
                    'base_rent_per_head' => (float)($r['rent_per_head'] ?? 0),
                    'rent_per_head' => $dynamicRentPerHead,
                    'rent' => $dynamicRentPerHead,
                    'deposit' => (float)$r['deposit'],
                    'occupants' => $numActiveOccupants,
                    'status' => $r['status'],
                    'amenities' => json_decode($r['amenities'] ?? '[]', true) ?: [],
                    'photo' => dorm_catalog_photo_list($r['photo'])[0] ?? null,
                    'photos' => dorm_catalog_photo_list($r['photo']),
                    'tenants' => array_values($activeOccupants),
                    'allTenants' => array_values($allRoomTenants),
                ];
            }
        }
    } catch (Throwable $e) {}

    $bookingsByProp = [];
    try {
        $bkStmt = $pdo->query("
          SELECT r.dorm_id, b.tenant_id, u.university, u.email
          FROM bookings b
          JOIN rooms r ON r.id = b.room_id
          JOIN tenants u ON u.id = b.tenant_id
          WHERE b.status = 'approved'
        ");
        if ($bkStmt) {
            foreach ($bkStmt->fetchAll(PDO::FETCH_ASSOC) as $bk) {
                $bookingsByProp[$bk['dorm_id']][] = $bk;
            }
        }
    } catch (Throwable $e) {}

    $userBookings = [];
    if ($currentUserId > 0) {
        try {
            $uStmt = $pdo->prepare("
              SELECT r.dorm_id, b.status
              FROM bookings b
              JOIN rooms r ON r.id = b.room_id
              WHERE b.tenant_id = ?
              ORDER BY
                CASE
                  WHEN b.status IN ('active', 'approved') THEN 4
                  WHEN b.status IN ('pending', 'upcoming') THEN 3
                  WHEN b.status IN ('cancelled', 'ended') THEN 2
                  ELSE 1
                END ASC,
                COALESCE(b.updated_at, b.created_at) ASC,
                b.id ASC
            ");
            $uStmt->execute([$currentUserId]);
            foreach ($uStmt->fetchAll(PDO::FETCH_ASSOC) as $ub) {
                $userBookings[(int)$ub['dorm_id']] = (string)$ub['status'];
            }
        } catch (Throwable $e) {}
    }

    return array_map(function ($p) use ($byProp, $bookingsByProp, $userBookings, $occupantsByProp, $tenantStatsByProp) {
        $photos = dorm_catalog_photo_list($p['photo']);
        $propBookings = $bookingsByProp[$p['id']] ?? [];
        $totalResidents = count($propBookings);
        $uni = (string)($p['nearest_uni'] ?? $p['university'] ?? '');
        $sameSchool = 0;
        $rawUserStayStatus = $userBookings[(int)$p['id']] ?? null;
        $hasBooked = in_array($rawUserStayStatus, ['approved', 'active', 'upcoming', 'pending'], true);

        $userStayStatus = $rawUserStayStatus;
        if ($rawUserStayStatus === 'approved') {
            $userStayStatus = 'active';
        } elseif ($rawUserStayStatus === 'pending') {
            $userStayStatus = 'pending';
        } elseif ($rawUserStayStatus === 'cancelled') {
            $userStayStatus = 'ended';
        }

        foreach ($propBookings as $b) {
            $userSchool = trim((string)($b['university'] ?? ''));
            $userEmail = trim((string)($b['email'] ?? ''));

            $match = false;
            if ($userSchool !== '' && strcasecmp($userSchool, $uni) === 0) {
                $match = true;
            } elseif (stripos($uni, 'dagupan') !== false && (stripos($userSchool, 'dagupan') !== false || stripos($userEmail, 'cdd.edu.ph') !== false)) {
                $match = true;
            } elseif (stripos($uni, 'luzon') !== false && stripos($userSchool, 'luzon') !== false) {
                $match = true;
            } elseif (stripos($uni, 'pangasinan') !== false && stripos($userSchool, 'pangasinan') !== false) {
                $match = true;
            }

            if ($match) {
                $sameSchool++;
            }
        }

        $livePct = $totalResidents > 0 ? (int)round(($sameSchool / $totalResidents) * 100) : 0;
        $propTenants = $occupantsByProp[(int)$p['id']] ?? [];
        $pStats = $tenantStatsByProp[(int)$p['id']] ?? ['approved' => 0, 'pending' => 0, 'declined' => 0, 'total' => 0];
        $landlordPhoto = resolve_landlord_photo_url($p['landlordProfilePhoto'] ?? null, $p['landlordPhoto'] ?? null);
        $social = parse_landlord_social($p['landlordFacebook'] ?? null);
        $memberSince = !empty($p['landlordCreatedAt']) ? date('F Y', strtotime($p['landlordCreatedAt'])) : '2024';
        $landlordVerified = in_array($p['landlordVerification'] ?? '', ['approved', 'verified'], true);

        $landlordInfo = [
            'id' => (int)($p['landlord_id'] ?? $p['landlord_user_id'] ?? 0),
            'name' => $p['landlordName'] ?: 'Landlord',
            'photo' => $landlordPhoto,
            'phone' => $p['landlordPhone'] ?: null,
            'email' => $p['landlordEmail'] ?: null,
            'facebook' => $social['facebook'],
            'messenger' => $social['messenger'],
            'facebookUsername' => $social['username'],
            'verification' => $p['landlordVerification'] ?: 'unverified',
            'verified' => $landlordVerified,
            'memberSince' => $memberSince,
        ];

        return [
            'id' => (int)$p['id'],
            'landlord_id' => (int)$p['landlord_id'],
            'name' => $p['name'],
            'university' => $uni,
            'nearest_uni' => $uni,
            'address' => $p['address'],
            'description' => $p['description'],
            'photo' => $photos[0] ?? '',
            'photos' => $photos,
            'proof_of_ownership' => $p['proof_of_ownership'] ?? null,
            'proofOfOwnership' => !empty($p['proof_of_ownership']) ? (strpos($p['proof_of_ownership'], 'http') === 0 ? $p['proof_of_ownership'] : '../' . ltrim($p['proof_of_ownership'], '/.')) : null,
            'business_permit' => $p['business_permit'] ?? null,
            'businessPermit' => !empty($p['business_permit']) ? (strpos($p['business_permit'], 'http') === 0 ? $p['business_permit'] : '../' . ltrim($p['business_permit'], '/.')) : null,
            'lat' => (float)$p['lat'],
            'lng' => (float)$p['lng'],
            'approvalStatus' => $p['approval_status'] ?? 'approved',
            'approved_by' => !empty($p['approved_by']) ? (int)$p['approved_by'] : null,
            'feedback' => $p['feedback'] ?? null,
            'tenantStats' => $pStats,
            'commonSchoolPct' => $totalResidents > 0 ? $livePct : (int)($p['common_school_pct'] ?? 0),
            'livePct' => $livePct,
            'totalResidents' => $totalResidents,
            'sameSchoolResidents' => $sameSchool,
            'hasBooked' => $hasBooked,
            'userStayStatus' => $userStayStatus,
            'landlordName' => $p['landlordName'],
            'landlordVerified' => $landlordVerified,
            'landlordPhoto' => $landlordPhoto,
            'landlord' => $landlordInfo,
            'tenants' => $propTenants,
            'rooms' => $byProp[$p['id']] ?? [],
        ];
    }, $props);
}

