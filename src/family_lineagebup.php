<?php
require_once __DIR__ . '/dbconfig.php';
$conn = $GLOBALS['conn'] ?? ($conn ?? null);
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    die('Database connection not established. Check src/dbconfig.php and MySQL service.');
}

include("backend/family_lineage_service.php");

$requestedMemberId = isset($_GET['member_id']) ? trim((string) $_GET['member_id']) : '';

try {
    $payload = build_family_lineage_payload($conn, $requestedMemberId);
    $family_data = $payload['family_data'];
    $selectedMemberId = $payload['selected_member_id'];
    $json_data = json_encode($family_data, JSON_PRETTY_PRINT);
} catch (RuntimeException $e) {
    die($e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.balkan.app/familytree.js"></script>
    <link rel="stylesheet" href="../css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <title>IP Lineage - Family Tree</title>
</head>
<body class="min-h-screen">
    <header class="bg-white border-b border-[#dedede] p-4 md:px-8 flex justify-between items-center">
        <div class="flex items-center gap-4">
            <a href="ip_members.php" class="p-2 hover:bg-gray-100 rounded-lg transition">
                <i data-lucide="arrow-left" class="w-5 h-5 text-gray-600"></i>
            </a>
            <h1 class="text-lg font-bold text-[#262626]">Family Tree</h1>
        </div>
    </header>

    <main class="p-10">
        <div id="FamilyChart" class="f3"></div>
    </main>

    <script>
    window.addEventListener('DOMContentLoaded', function() {
        lucide.createIcons();

        try {
            const data = <?php echo $json_data; ?>;
            const selectedMemberId = <?php echo json_encode($selectedMemberId); ?>;

            if (!data || data.length === 0) {
                document.getElementById('FamilyChart').innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#ccc;font-size:16px;">No family data available. Please add members to the database.</div>';
                return;
            }

            const byId = new Map(data.map((person) => [String(person.id), person]));

            function normalizeGender(rawGender) {
                const g = String(rawGender || 'U').toUpperCase();
                if (g === 'FEMALE' || g === 'F') {
                    return 'female';
                }
                if (g === 'MALE' || g === 'M') {
                    return 'male';
                }
                return 'male';
            }

            function resolveParents(parentIds) {
                const result = { mid: null, fid: null };

                for (const parentId of parentIds) {
                    const parent = byId.get(String(parentId));
                    const gender = normalizeGender(parent?.data?.gender);

                    if (gender === 'female' && result.mid === null) {
                        result.mid = String(parentId);
                        continue;
                    }
                    if (gender === 'male' && result.fid === null) {
                        result.fid = String(parentId);
                        continue;
                    }

                    if (result.fid === null) {
                        result.fid = String(parentId);
                    } else if (result.mid === null) {
                        result.mid = String(parentId);
                    }
                }

                return result;
            }

            const nodes = data.map((person) => {
                const personId = String(person.id);
                const firstName = String(person.data?.['first name'] || '').trim();
                const lastName = String(person.data?.['last name'] || '').trim();
                const fullName = `${firstName} ${lastName}`.trim() || 'Unknown';
                const initials = `${firstName.charAt(0)}${lastName.charAt(0)}`.trim().toUpperCase() || 'U';
                const displayId = String(person.data?.display_id || personId);
                const parentIds = (person.rels?.parents || []).map(String).filter((id) => byId.has(id));
                const spouseIds = (person.rels?.spouses || []).map(String).filter((id) => byId.has(id));
                const parents = resolveParents(parentIds);

                const node = {
                    id: personId,
                    pids: Array.from(new Set(spouseIds)),
                    name: fullName,
                    gender: normalizeGender(person.data?.gender),
                    initials: initials,
                    display_id: displayId,
                    birthday: String(person.data?.birthday || ''),
                    tags: [normalizeGender(person.data?.gender) === 'female' ? 'female' : 'male']
                    
                };

                if (parents.mid !== null) {
                    node.mid = parents.mid;
                }
                if (parents.fid !== null) {
                    node.fid = parents.fid;
                }

                return node;
            });

            const NODE_WIDTH = 150;
            const NODE_HEIGHT = 200;
            const NODE_RADIUS = 10;
            const NODE_BORDER_WIDTH = 1.5;
            const ACCENT_THICKNESS = 6;
            const NAME_SIZE = 14;
            const ID_SIZE = 11;
            const AVATAR_SIZE = 64;

            const GENDER_STYLE = {
                male: {
                    bg: '#EFF6FF',
                    accent: '#3B82F6'
                },
                female: {
                    bg: '#FFF1F5',
                    accent: '#EC4899'
                },
            };

            function buildNodeTemplate(bg, accent) {
                const midX = NODE_WIDTH / 2;
                const avatarRadius = AVATAR_SIZE / 2;
                const avatarCx = midX;
                const avatarCy = 56;
                const nameY = avatarCy + avatarRadius + 28;
                const idY = nameY + 22;
                // Extend base so all required internal fields (buttons, links, etc.) are inherited
                const tpl = Object.assign({}, FamilyTree.templates.base);
                tpl.size     = [NODE_WIDTH, NODE_HEIGHT];
                tpl.miniSize = [50, 50];
                // Node background + top accent strip (like old node-card top border)
                tpl.node =
                    `<rect width="${NODE_WIDTH}" height="${NODE_HEIGHT}" rx="${NODE_RADIUS}" ry="${NODE_RADIUS}" fill="${bg}" stroke="${accent}" stroke-width="${NODE_BORDER_WIDTH}"></rect>` +
                    `<circle cx="${avatarCx}" cy="${avatarCy}" r="${avatarRadius}" fill="${accent}" opacity="0.15"></circle>` +
                    `<rect width="${NODE_WIDTH}" height="${ACCENT_THICKNESS}" rx="${Math.max(2, NODE_RADIUS / 2)}" ry="${Math.max(2, NODE_RADIUS / 2)}" fill="${accent}"></rect>`;
                // field_0 = name  |  field_1 = display_id
                tpl.field_2 =
                    `<text style="font-size:22px;font-weight:800;font-family:'Plus Jakarta Sans',sans-serif" fill="${accent}" x="${avatarCx}" y="${avatarCy + 7}" text-anchor="middle">{val}</text>`;
                tpl.field_0 =
                    `<text style="font-size:${NAME_SIZE}px;font-weight:700;font-family:'Plus Jakarta Sans',sans-serif" fill="#111827" x="${midX}" y="${nameY}" text-anchor="middle">{val}</text>`;
                tpl.field_1 =
                    `<text style="font-size:${ID_SIZE}px;font-family:'Plus Jakarta Sans',sans-serif" fill="#6B7280" x="${midX}" y="${idY}" text-anchor="middle">{val}</text>`;
                return tpl;
            }

            Object.entries(GENDER_STYLE).forEach(([gender, style]) => {
                FamilyTree.templates['ip_' + gender] = buildNodeTemplate(style.bg, style.accent);
            });
            // ─────────────────────────────────────────────────────────────

            const tree = new FamilyTree('#FamilyChart', {
                enableSearch: false,
                nodeBinding: {
                    field_2: 'initials',
                    field_0: 'name',
                    field_1: 'display_id',
                },
                tags: {
                    male:   { template: 'ip_male' },
                    female: { template: 'ip_female' },
                },
                nodes: nodes
            });

            tree.onInit(function() {
                const hasSelected = selectedMemberId && nodes.some((n) => String(n.id) === String(selectedMemberId));
                if (hasSelected) {
                    tree.center(String(selectedMemberId));
                } else {
                    tree.fit();
                }
            });
        } catch (error) {
            console.error('Error initializing family tree:', error);
            document.getElementById('FamilyChart').innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#dc2626;font-size:16px;">Error loading family tree: ' + (error.message || error) + '</div>';
        }
    });
    </script>
</body>
</html>