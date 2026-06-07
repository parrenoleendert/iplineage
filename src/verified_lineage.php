<?php
require_once __DIR__ . '/auth/guards.php';
require_once __DIR__ . '/auth/auth_helpers.php';
require_any_role(['admin', 'tribe_leader', 'ip_member']);

require_once __DIR__ . '/dbconfig.php';
$conn = $GLOBALS['conn'] ?? ($conn ?? null);
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    die('Database connection not established. Check src/dbconfig.php and MySQL service.');
}

$activeNav = 'ip_members';

include("backend/family_lineage_service.php");

$currentRole = normalize_role((string) ($_SESSION['role'] ?? ''));

// Determine where the "Back" button should go
$backHref = 'ip_members.php';
// Always go back to ip_members as the previous requirement/module flow
// (previously admin/tribe_leader routed to pending_lineage.php)
if (false && ($currentRole === 'admin' || $currentRole === 'tribe_leader')) {
    $backHref = 'pending_lineage.php';
}

$requestedMemberId = isset($_GET['member_id']) ? trim((string) $_GET['member_id']) : '';

$uid = (int)($_SESSION['user_id'] ?? 0);
$sessionMemberId = '';
$sessionMaritalStatus = '';
$res = $conn->query("SELECT i.ip_member_id, d.marital_status FROM ipmembers i LEFT JOIN ip_member_details d ON i.ip_member_id = d.ip_member_id WHERE i.user_id = $uid LIMIT 1");
if ($res && $row = $res->fetch_assoc()) {
    $sessionMemberId = (string)$row['ip_member_id'];
    $sessionMaritalStatus = strtolower(trim((string)($row['marital_status'] ?? '')));
}

// Default to own record if no ID specified
if ($requestedMemberId === '') {
    $requestedMemberId = $sessionMemberId;
}

$isAdminOrElder = in_array($currentRole, ['admin', 'tribe_leader'], true);

try {
    $payload = build_family_lineage_payload($conn, $requestedMemberId, $isAdminOrElder);
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
    <script src="https://unpkg.com/d3@7"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script type="module" src="https://unpkg.com/family-chart@0.9.0"></script>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="https://unpkg.com/family-chart@0.9.0/dist/styles/family-chart.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <title>IP Lineage - Family Lineage</title>
    <style>
        .add-parent-action {
            position: absolute;
            top: 6px;
            right: 6px;
            width: 24px;
            height: 24px;
            background: #262626;
            color: white;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            border: 2px solid white;
            pointer-events: auto;
            z-index: 50;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .add-parent-action:hover { transform: scale(1.1); background: #000; }

        .add-spouse-action {
            position: absolute;
            top: 34px;
            right: 6px;
            width: 24px;
            height: 24px;
            background: #4b5563;
            color: white;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            cursor: pointer;
            border: 2px solid white;
            z-index: 50;
            transition: all 0.2s;
        }
        .add-spouse-action:hover { transform: scale(1.1); background: #1f2937; }

        .edit-node-action {
            position: absolute;
            top: 6px;
            left: 6px;
            width: 24px;
            height: 24px;
            background: #9ca3af;
            color: white;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 2px solid white;
            z-index: 50;
            transition: all 0.2s;
        }
        .edit-node-action:hover { transform: scale(1.1); background: #6b7280; }
    </style>
</head>
<body class="min-h-screen">
    <header class="bg-white border-b border-[#dedede] p-4 md:px-8 flex justify-between items-center">
        <div class="flex items-center gap-4">
            <a href="<?php echo $backHref; ?>" class="p-2 hover:bg-gray-100 rounded-lg transition">
                <i data-lucide="arrow-left" class="w-5 h-5 text-gray-600"></i>
            </a>
            <div>
                <h1 class="text-lg font-bold text-[#262626]">Verified Lineage</h1>
            </div>
        </div>
    </header>

    <main class="p-4 md:p-10"> 
        <div class="lineage-layout relative"> 
            <div id="FamilyChart" class="f3"></div>
            <aside id="ProfilePanel" class="profile-panel is-hidden">
                <h2 class="profile-title">Official Profile</h2> 
                <div id="ProfilePanelBody" class="profile-body"> 
                    Click a person to view details. 
                </div> 
            </aside>
        </div> 
    </main>
    <script type="module">
    window.addEventListener('DOMContentLoaded', function() {
        lucide.createIcons();
        
        try {
            const data = <?php echo $json_data; ?>;
            const selectedMemberId = <?php echo json_encode($selectedMemberId); ?>;
            const currentRole = <?php echo json_encode($currentRole); ?>;
            const isAdminOrElder = currentRole === 'admin' || currentRole === 'tribe_leader';
            const ancestryOnly = (currentRole === 'ip_member');
            const sessionMemberId = <?php echo json_encode($sessionMemberId); ?>;
            const sessionMaritalStatus = <?php echo json_encode($sessionMaritalStatus); ?>;
            const isTreeOwner = sessionMemberId !== '' && String(sessionMemberId) === String(selectedMemberId);
            const profilePanel = document.getElementById('ProfilePanel');
            const profilePanelBody = document.getElementById('ProfilePanelBody');
            
            console.log('Family Chart Data:', data);
            console.log('Selected Member ID:', selectedMemberId);
            
            if (!data || data.length === 0) {
                document.getElementById('FamilyChart').innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#ccc;font-size:16px;">No family data available. Please add members to the database.</div>';
                return;
            }

            const getMemberById = (id) => data.find((member) => String(member.id) === String(id));

const getDisplayName = (member) => {
                const firstName = String(member?.data?.['first name'] || '').trim();
                const lastName = String(member?.data?.['last name'] || '').trim();
                const fullName = `${firstName} ${lastName}`.trim();
                if (!fullName) return 'Not Registered';

                return fullName.toLowerCase().split(' ')
                    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
                    .join(' ');
            };

            const getDisplayGender = (member) => {
                const rawGender = String(member?.data?._original_gender || member?.data?.gender || 'U').toUpperCase();
                if (rawGender === 'F' || rawGender === 'FEMALE') {
                    return 'Female';
                }
                if (rawGender === 'M' || rawGender === 'MALE') {
                    return 'Male';
                }
                return 'Unknown';
            };

            const getRelatedNames = (ids = []) => ids
                .map((id) => getMemberById(id))
                .filter((member) => !!member)
                .map((member) => getDisplayName(member));

            const createChartData = () => data.map((member) => ({
                ...member,
                data: { ...(member.data || {}) },
                rels: {
                    parents: [...(member.rels?.parents || [])],
                    spouses: [...(member.rels?.spouses || [])],
                    children: [...(member.rels?.children || [])]
                }
            }));

            const getSiblingItems = (member) => {
                const parentIds = (member?.rels?.parents || []).map((id) => String(id));
                if (!parentIds.length) {
                    return [];
                }

                return data
                    .filter((otherMember) => {
                        if (String(otherMember.id) === String(member.id)) {
                            return false;
                        }

                        const otherParentIds = (otherMember?.rels?.parents || []).map((id) => String(id));
                        return otherParentIds.some((parentId) => parentIds.includes(parentId));
                    })
                    .map((otherMember) => {
                        const gender = getDisplayGender(otherMember);
                        const relationLabel = gender === 'Male'
                            ? 'Brother'
                            : (gender === 'Female' ? 'Sister' : 'Sibling');

                        return `${relationLabel}: ${getDisplayName(otherMember)}`;
                    });
            };

            const renderList = (items) => {
                if (!items.length) {
                    return '<span class="profile-empty">None</span>';
                }

                return `<ul class="profile-list">${items.map((item) => `<li>${item}</li>`).join('')}</ul>`;
            };

            const hideProfilePanel = () => {
                if (profilePanel) {
                    profilePanel.classList.add('is-hidden');
                }
            };

            const updateProfilePanel = (memberId) => {
                if (!profilePanelBody) {
                    return;
                }

                if (profilePanel) {
                    profilePanel.classList.remove('is-hidden');
                }

                const member = getMemberById(memberId);
                if (!member) {
                    profilePanelBody.textContent = 'Profile not found.';
                    return;
                }

                const rels = member.rels || {};
                const parents = getRelatedNames(rels.parents || []);
                const siblings = getSiblingItems(member);
                const spouses = getRelatedNames(rels.spouses || []);
                const children = getRelatedNames(rels.children || []);

                const nodeData = member.data || {};

                profilePanelBody.innerHTML = `
                    <div class="profile-row"><span class="profile-label">Name</span><span class="profile-value">${getDisplayName(member)}</span></div>
                    <div class="profile-row"><span class="profile-label">Official ID</span><span class="profile-value text-emerald-700">${String(member.data?.display_id || 'Verified')}</span></div>
                    <div class="profile-row"><span class="profile-label">Gender</span><span class="profile-value">${getDisplayGender(member)}</span></div>
                    <div class="profile-row"><span class="profile-label">Birthday</span><span class="profile-value">${String(member.data?.birthdate || '').trim() || 'N/A'}</span></div>
                    <div class="profile-section">
                        <h3>Parents</h3>
                        ${renderList(parents)}
                    </div>
                    <div class="profile-section">
                        <h3>Siblings</h3>
                        ${renderList(siblings)}
                    </div>
                    <div class="profile-section">
                        <h3>Spouses</h3>
                        ${renderList(spouses)}
                    </div>
                    <div class="profile-section">
                        <h3>Children</h3>
                        ${renderList(children)}
                    </div>
                `;

                if (window.lucide) lucide.createIcons();
            };

            // Store original genders for all members before we modify any
            data.forEach((member) => {
                if (member.data) {
                    member.data._original_gender = member.data.gender || 'U';
                }
            });

            // Identify direct bloodline (Self + Ancestors) to restrict parent addition
            const lineageGenMap = new Map();
            const findLineage = (id, gen) => {
                const mid = String(id);
                if (!mid) return;
                if (lineageGenMap.has(mid) && lineageGenMap.get(mid) <= gen) return;
                
                const m = getMemberById(mid);
                lineageGenMap.set(mid, gen);

                if (!m) return;

                // Stop traversing up if the node is rejected or cancelled.
                // This hides the ancestors of a rejected parent while keeping the parent visible for review.
                const requestStatus = m.data?.request_status || (m.data?.is_ghost ? 'pending' : 'approved');
                if (requestStatus === 'rejected' || requestStatus === 'cancelled') {
                    return;
                }

                if (m?.rels?.parents) m.rels.parents.forEach(pId => findLineage(pId, gen + 1));
            };
            
            if (selectedMemberId) findLineage(selectedMemberId, 1);

            // Build restricted node list: Me + My Ancestors + My Spouses + My Siblings
            const directLineageIds = new Set(lineageGenMap.keys());
            const siblingIds = new Set();
            const spouseIds = new Set();

            if (selectedMemberId) {
                const mainNode = getMemberById(selectedMemberId);
                const myParents = (mainNode?.rels?.parents || []).map(String);
                
                data.forEach(m => {
                    const mid = String(m.id);
                    // Identify Siblings
                    if (mid !== String(selectedMemberId) && (m.rels?.parents || []).some(p => myParents.includes(String(p)))) {
                        siblingIds.add(mid);
                    }
                    // Identify Spouses of lineage members
                    if (directLineageIds.has(mid)) {
                        (m.rels?.spouses || []).forEach(sId => spouseIds.add(String(sId)));
                    }
                });
            }

            // Build strict verified-only dataset
            const filteredNodes = createChartData().filter(m => {
                const mid = String(m.id);
                
                // 1. Must be verified
                if (!m.data?.is_verified) return false;

                // 2. Elders see entire verified community registry
                if (isAdminOrElder) return true;

                // 3. Members see their own verified biological component
                const allowedComponentIds = new Set([...directLineageIds, ...siblingIds, ...spouseIds]);
                return allowedComponentIds.has(mid);
            });

            const survivingIds = new Set(filteredNodes.map(n => String(n.id)));

            // 2. Process surviving nodes and clean up relationship pointers
            const chartData = filteredNodes.map(m => {
                const mid = String(m.id);
                if (!m.rels) m.rels = { parents: [], spouses: [], children: [] };

                // Synchronize relationship arrays with the current visibility filter
                m.rels.parents = (m.rels.parents || []).map(String).filter(id => survivingIds.has(id));
                m.rels.spouses = (m.rels.spouses || []).map(String).filter(id => survivingIds.has(id));
                m.rels.children = (m.rels.children || []).map(String).filter(id => survivingIds.has(id));

                if (ancestryOnly) m.rels.children = [];

                    // Layout fix: force main member to show as 'M' for consistent left-side positioning
                    if (mid === String(selectedMemberId)) m.data.gender = 'M';

                    return m;
                });
            // Create the family chart using family-chart library
            const mainId = String(selectedMemberId || '');

            // === Lineage layout settings - adjust these to change spacing and visibility ===
            // - setCardXSpacing(value): horizontal spacing between cards
            // - setCardYSpacing(value): vertical spacing between card rows
            // - setShowSiblingsOfMain(true/false): show sibling group for main person
            // To change card appearance, edit the setCardInnerHtmlCreator below.
            const f3Chart = f3.createChart('#FamilyChart', chartData)
                .setTransitionTime(1000)
                .setCardXSpacing(250) // adjust horizontal gap here
                .setCardYSpacing(310) // adjust vertical gap here
                .setShowSiblingsOfMain(true)
                .setSortChildrenFunction((a, b) => {
                    const aId = String(a.id || '');
                    const bId = String(b.id || '');

                    if (aId === mainId) return -1;   // others to right
                    if (bId === mainId) return 1;  // -1 if position adjust of sibling to left

                    return aId.localeCompare(bId);
                });

            if (ancestryOnly) {
                f3Chart
                    .setProgenyDepth(0)
                    .setSingleParentEmptyCard(false);
            }

            // Configure card design (colors and node layout)
            // === Card HTML and content ===
            // Edit the inner HTML below to change what appears on each node.
            // The function `setCardInnerHtmlCreator` returns the HTML for a node.
            f3Chart.setCardHtml()
                .setStyle('rect')
                .setOnCardClick((event, node) => {
                    event.stopPropagation();
    
                    const memberId = String(node?.data?.id || '');
                    if (!memberId) {
                        return;
                    }

                    updateProfilePanel(memberId);
                })
                .setCardInnerHtmlCreator((d) => {
                    const nodeData = d.data.data || {};
                    const fullName = `${nodeData["first name"] || ''} ${nodeData["last name"] || ''}`.trim() || 'Unknown';
                    // Use original gender if stored (for selected member), otherwise use current gender
                    const genderRaw = String(nodeData._original_gender || nodeData.gender || 'U').toUpperCase();
                    const isFemale = genderRaw === 'FEMALE' || genderRaw === 'F';

                    const getIdLabel = (node) => (node?.is_ghost ? 'Unregistered' : (node?.display_id || 'N/A'));

                    const isMale = genderRaw === 'MALE' || genderRaw === 'M';
                    const gender = isFemale ? 'F' : (isMale ? 'M' : 'U');
                    
                    const isSelected = selectedMemberId && String(d.data.id) === String(selectedMemberId);

                    const nameParts = fullName.split(' ').filter(p => p.length > 0);
                    const initials = nameParts.length >= 2
                        ? (nameParts[0][0] + nameParts[nameParts.length - 1][0]).toUpperCase()
                        : fullName.substring(0, 2).toUpperCase();
                    const avatarClass = gender === 'F' ? 'node-avatar node-avatar-female' : (gender === 'M' ? 'node-avatar node-avatar-male' : 'node-avatar node-avatar-unknown');

                    return `
                        <div class="node-card relative ${gender === 'F' ? 'node-female' : 'node-male'} ${isSelected ? 'node-selected' : ''}">
                            <div class="${avatarClass}">${initials}</div>
                            <div class="node-top">
                                <div class="node-name">${fullName}</div>
                            </div>
                            <div class="mt-2.5 mb-2 flex justify-center w-full">
                                ${nodeData.is_ghost ? '' : 
                                    `<span class="text-[9px] font-bold text-emerald-700 bg-emerald-100/50 px-2 py-0.5 rounded-full uppercase tracking-tighter">Verified Member</span>`
                                }
                            </div>

                            <div class="node-meta mt-2 space-y-1">
                                <div class="flex justify-between items-center text-[9px] bg-white/50 border border-gray-100 p-1.5 rounded-lg">
                                    <span class="text-gray-400 font-bold uppercase tracking-tighter">ID:</span>
                                    <span class="text-neutral-700 font-bold tracking-tight">${(nodeData.is_ghost || !nodeData.display_id) ? 'Not Registered' : nodeData.display_id}</span>
                                </div>
                                <div class="flex justify-between items-center text-[9px] bg-white/50 border border-gray-100 p-1.5 rounded-lg">
                                    <span class="text-gray-400 font-bold uppercase tracking-tighter">Birthday:</span>
                                    <span class="text-neutral-700 font-bold tracking-tight">${nodeData.birthdate || 'Not Registered'}</span>
                                </div>
                            </div>
                        </div>
                    `;
                });

            // Render with selected member as the main anchor
            if (selectedMemberId && data.some(d => String(d.id) === String(selectedMemberId))) {
                const isSurviving = filteredNodes.some(n => String(n.id) === String(selectedMemberId));
                if (isSurviving) {
                    f3Chart.updateMainId(String(selectedMemberId));
                    f3Chart.updateTree({initial: true, tree_position: 'main_to_middle'});
                } else { f3Chart.updateTree({initial: true}); }
            } else {
                f3Chart.updateTree({initial: true});
            }

            document.addEventListener('click', (event) => {
                const target = event.target;
                if (!(target instanceof Element)) {
                    return;
                }

                const clickedInsidePanel = profilePanel ? profilePanel.contains(target) : false;
                const clickedCard = !!target.closest('.card');

                if (!clickedInsidePanel && !clickedCard) {
                    hideProfilePanel();
                }
            });

            console.log('Family chart rendered successfully', { ancestryOnly });
        
        } catch (error) {
            console.error('Error initializing family chart:', error);
            document.getElementById('FamilyChart').innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#dc2626;font-size:16px;">Error loading family tree: ' + error.message + '</div>';
        }
    });
    </script>
</body>
</html>