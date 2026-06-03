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
    <script src="https://unpkg.com/d3@7"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script type="module" src="https://unpkg.com/family-chart@0.9.0"></script>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="https://unpkg.com/family-chart@0.9.0/dist/styles/family-chart.css">
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

    <main class="p-4 md:p-10"> 
        <div class="lineage-layout relative"> 
            <div id="FamilyChart" class="f3"></div>

            <!-- Floating Legend (top-left) - vertical rectangle samples -->
            <div id="FamilyLegend" class="absolute top-7 right-7 z-1000">
                <div class="bg-white border border-gray-200 rounded-lg shadow p-3 text-sm text-gray-700 w-45">
                    <div class="font-bold mb-2">Legend</div>
                    <div class="space-y-2">

                            <div class="flex items-center gap-3">
                            <!-- Male sample -->
                            <div class="node-card node-male" style="min-width:70px; min-height:50px; padding:10px;">
                                <div style= "height: 30px; width: 30px; font-size: 12px;" class="node-avatar node-avatar-male">AB</div>
                                <div class="node-top">
                                    <div class="node-name">Male</div>
                                </div>
                                <div class="node-meta"><div><strong>ID:</strong> 123</div></div>
                            </div>

                            <!-- Female sample -->
                            <div class="node-card node-female" style="min-width:70px; min-height:50px; padding:10px;">
                                <div style= "height: 30px; width: 30px; font-size: 12px;" class="node-avatar node-avatar-female">CD</div>
                                <div class="node-top">
                                    <div class="node-name">Female</div>
                                </div>
                                <div class="node-meta"><div><strong>ID:</strong> 124</div></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <aside id="ProfilePanel" class="profile-panel is-hidden">
                <h2 class="profile-title">Profile</h2> 
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
            const ancestryOnly = true;
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
                return fullName || 'Unknown';
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

                profilePanelBody.innerHTML = `
                    <div class="profile-row"><span class="profile-label">Name</span><span class="profile-value">${getDisplayName(member)}</span></div>
                    <div class="profile-row"><span class="profile-label">ID</span><span class="profile-value">${String(member.data?.display_id || member.id || '')}</span></div>
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
            };

            // Store original genders for all members before we modify any
            data.forEach((member) => {
                if (member.data) {
                    member.data._original_gender = member.data.gender || 'U';
                }
            });

            const chartData = createChartData();
            const getChartMemberById = (id) => chartData.find((member) => String(member.id) === String(id));

            // Layout fix: keep selected person on the left side of spouse pair.
            if (selectedMemberId) {
                const selectedNode = getChartMemberById(selectedMemberId);
                if (selectedNode && selectedNode.data) {
                    selectedNode.data.gender = 'M';
                }
            }

            if (selectedMemberId) {
                const selectedNode = getChartMemberById(selectedMemberId);
                const selectedParentIds = (selectedNode?.rels?.parents || []).map((id) => String(id));
                const selectedSpouseIds = (selectedNode?.rels?.spouses || []).map((id) => String(id));
                const siblingIds = chartData
                    .filter((member) => {
                        if (String(member.id) === String(selectedMemberId)) {
                            return false;
                        }

                        const parentIds = (member.rels?.parents || []).map((id) => String(id));
                        return parentIds.some((parentId) => selectedParentIds.includes(parentId));
                    })
                    .map((member) => String(member.id));

                selectedSpouseIds.forEach((spouseId) => {
                    const spouse = getChartMemberById(spouseId);
                    if (spouse && spouse.rels) {
                        spouse.rels.parents = [];
                    }
                });

                siblingIds.forEach((siblingId) => {
                    const sibling = getChartMemberById(siblingId);
                    if (!sibling || !sibling.rels) {
                        return;
                    }

                    const siblingSpouseIds = [...(sibling.rels.spouses || [])].map((id) => String(id));
                    sibling.rels.spouses = [];

                    siblingSpouseIds.forEach((spouseId) => {
                        const spouse = getChartMemberById(spouseId);
                        if (spouse && spouse.rels) {
                            spouse.rels.spouses = (spouse.rels.spouses || []).filter((id) => String(id) !== siblingId);
                        }
                    });
                });
            }

            // Ancestry-only mode: hide descendants but keep spouses.
            if (ancestryOnly) {
                chartData.forEach((person) => {
                    if (!person.rels) {
                        person.rels = { parents: [], spouses: [], children: [] };
                    }
                    person.rels.children = [];
                });
            }
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
                .setCardYSpacing(280) // adjust vertical gap here
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
                    const isMale = genderRaw === 'MALE' || genderRaw === 'M';
                    const gender = isFemale ? 'F' : (isMale ? 'M' : 'U');
                    const isSelected = selectedMemberId && String(d.data.id) === String(selectedMemberId);
                    const cardClass = `node-card ${gender === 'F' ? 'node-female' : 'node-male'} ${isSelected ? 'node-selected' : ''}`;

                    const nameParts = fullName.split(' ').filter(p => p.length > 0);
                    const initials = nameParts.length >= 2
                        ? (nameParts[0][0] + nameParts[nameParts.length - 1][0]).toUpperCase()
                        : fullName.substring(0, 2).toUpperCase();
                    const avatarClass = gender === 'F' ? 'node-avatar node-avatar-female' : (gender === 'M' ? 'node-avatar node-avatar-male' : 'node-avatar node-avatar-unknown');

                    return `
                        <div class="${cardClass}">
                            <div class="${avatarClass}">${initials}</div>
                            <div class="node-top">
                                <div class="node-name">${fullName}</div>
                            </div>
                            <div class="node-meta">
                                <div><strong>ID:</strong> ${nodeData.display_id || d.data.id}</div>
                            </div>
                        </div>
                    `;
                });

            // Render with selected member as the main anchor (left/start focus).
            if (selectedMemberId && data.some(d => String(d.id) === String(selectedMemberId))) {
                f3Chart.updateMainId(String(selectedMemberId));
                f3Chart.updateTree({initial: true, tree_position: 'main_to_middle'});
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