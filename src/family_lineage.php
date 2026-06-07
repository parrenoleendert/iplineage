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
if ($currentRole === 'admin' || $currentRole === 'tribe_leader') {
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
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
            <h1 class="text-lg font-bold text-[#262626]">Family Lineage</h1>
        </div>
    </header>

    <main class="p-4 md:p-10"> 
        <div class="lineage-layout relative"> 
            <div id="FamilyChart" class="f3"></div>

            <!-- Floating Legend (top-left) - vertical rectangle samples -->
            <div id="FamilyLegend" class="absolute top-7 right-7 z-99999999999">
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

    <!-- Add Parents Modal -->
    <div id="AddParentsModal" class="hidden fixed inset-0 z-[2000] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeAddParentsModal()"></div>
        <div class="relative z-10 w-full max-w-md bg-white rounded-2xl shadow-2xl border border-gray-200 p-8">
            <div class="flex items-center gap-3 mb-6">
                <div class="p-2 bg-neutral-100 rounded-lg text-neutral-900"><i data-lucide="user-plus" class="w-5 h-5"></i></div>
                <h3 class="text-xl font-bold text-[#262626]">Add Parents</h3>
            </div>
            <p class="text-xs text-gray-500 mb-8 leading-relaxed">Provide the names of parents for <span id="TargetPersonName" class="font-bold text-neutral-900"></span>.</p>
            <form id="AddParentsForm" class="space-y-6">
                <input type="hidden" name="child_id" id="ChildIdInput">
                <input type="hidden" name="depends_on_request_id" id="DependsOnInput">
                <div class="grid grid-cols-1 gap-4">
                    <div class="space-y-2">
                        <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest">Father's Full Name</label>
                        <input type="text" name="father_name" placeholder="Full Name" class="w-full bg-neutral-50 border border-neutral-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-neutral-400 transition">
                    </div>
                    <div class="space-y-2">
                        <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest">Father's Birthday</label>
                        <input type="date" name="father_dob" class="w-full bg-neutral-50 border border-neutral-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-neutral-400 transition">
                    </div>
                </div>
                <div class="grid grid-cols-1 gap-4 pt-4 border-t border-neutral-100">
                    <div class="space-y-2">
                        <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest">Mother's Full Name (Maiden)</label>
                        <input type="text" name="mother_name" placeholder="Full Name" class="w-full bg-neutral-50 border border-neutral-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-neutral-400 transition">
                    </div>
                    <div class="space-y-2">
                        <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest">Mother's Birthday</label>
                        <input type="date" name="mother_dob" class="w-full bg-neutral-50 border border-neutral-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-neutral-400 transition">
                    </div>
                </div>
                <div class="flex justify-end gap-3 mt-8 pt-4 border-t border-neutral-100">
                    <button type="button" onclick="closeAddParentsModal()" class="px-5 py-2.5 text-xs font-bold border border-neutral-200 rounded-xl hover:bg-neutral-50 transition text-neutral-600">Cancel</button>
                    <button type="submit" class="px-6 py-2.5 text-xs font-bold bg-[#262626] text-white rounded-xl hover:bg-black transition shadow-lg shadow-black/10">Save Lineage</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Spouse Modal -->
    <div id="AddSpouseModal" class="hidden fixed inset-0 z-[2000] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeAddSpouseModal()"></div>
        <div class="relative z-10 w-full max-w-md bg-white rounded-2xl shadow-2xl border border-gray-200 p-8">
            <div class="flex items-center gap-3 mb-6">
                <div class="p-2 bg-neutral-100 rounded-lg text-neutral-900"><i data-lucide="user-plus" class="w-5 h-5"></i></div>
                <h3 class="text-xl font-bold text-[#262626]">Add Spouse</h3>
            </div>
            <p class="text-xs text-gray-500 mb-8 leading-relaxed">Provide the details for the spouse of <span id="SpouseTargetName" class="font-bold text-neutral-900"></span>.</p>
            <form id="AddSpouseForm" class="space-y-6">
                <input type="hidden" name="person_id" id="SpousePersonIdInput">
                <div class="space-y-4">
                    <div class="space-y-2">
                        <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest">Spouse's Full Name</label>
                        <input type="text" name="spouse_name" required placeholder="Full Name" class="w-full bg-neutral-50 border border-neutral-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-neutral-400 transition">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest">Gender</label>
                            <select name="spouse_sex" class="w-full bg-neutral-50 border border-neutral-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-neutral-400 transition">
                                <option value="female">Female</option>
                                <option value="male">Male</option>
                            </select>
                        </div>
                        <div class="space-y-2">
                            <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest">Birthday</label>
                            <input type="date" name="spouse_dob" class="w-full bg-neutral-50 border border-neutral-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-neutral-400 transition">
                        </div>
                    </div>
                </div>
                <div class="flex justify-end gap-3 mt-8 pt-4 border-t border-neutral-100">
                    <button type="button" onclick="closeAddSpouseModal()" class="px-5 py-2.5 text-xs font-bold border border-neutral-200 rounded-xl hover:bg-neutral-50 transition text-neutral-600">Cancel</button>
                    <button type="submit" class="px-6 py-2.5 text-xs font-bold bg-[#262626] text-white rounded-xl hover:bg-black transition shadow-lg shadow-black/10">Add Spouse</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Node Modal -->
    <div id="EditNodeModal" class="hidden fixed inset-0 z-[2000] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeEditNodeModal()"></div>
        <div class="relative z-10 w-full max-w-md bg-white rounded-2xl shadow-2xl border border-gray-200 p-8">
            <div class="flex items-center gap-3 mb-6">
                <div class="p-2 bg-blue-50 text-blue-600 rounded-lg"><i data-lucide="edit-3" class="w-5 h-5"></i></div>
                <h3 class="text-xl font-bold text-[#262626]">Edit Lineage Details</h3>
            </div>
            <p class="text-xs text-gray-500 mb-8 leading-relaxed">Fix the details for <span id="EditTargetName" class="font-bold text-neutral-900"></span> to comply with verification requirements.</p>
            <form id="EditNodeForm" class="space-y-6">
                <input type="hidden" name="request_id" id="EditRequestId">
                <div class="space-y-4">
                    <div class="space-y-2">
                        <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest">Correct Full Name</label>
                        <input type="text" name="proposed_name" id="EditNameInput" required class="w-full bg-neutral-50 border border-neutral-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-blue-400 transition">
                    </div>
                    <div class="space-y-2">
                        <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest">Correct Birthday</label>
                        <input type="date" name="proposed_dob" id="EditDobInput" class="w-full bg-neutral-50 border border-neutral-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-blue-400 transition">
                    </div>
                </div>
                <div class="flex justify-end gap-3 mt-8 pt-4 border-t border-neutral-100">
                    <button type="button" onclick="closeEditNodeModal()" class="px-5 py-2.5 text-xs font-bold border border-neutral-200 rounded-xl hover:bg-neutral-50 transition text-neutral-600">Cancel</button>
                    <button type="submit" class="px-6 py-2.5 text-xs font-bold bg-blue-600 text-white rounded-xl hover:bg-blue-700 transition shadow-lg shadow-blue-200">Resubmit for Verification</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Rejection Remarks Modal -->
    <div id="RejectionRemarksModal" class="hidden fixed inset-0 z-[2000] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeRejectionModal()"></div>
        <div class="relative z-10 w-full max-w-sm bg-white rounded-2xl p-8 shadow-2xl border border-gray-100">
            <h3 class="text-lg font-bold text-[#262626] mb-2">Reject Update</h3>
            <p class="text-xs text-gray-500 mb-6 leading-relaxed">Please provide a reason for rejecting the lineage update for <span id="RejectTargetName" class="font-bold text-neutral-900"></span>.</p>
            <form id="RejectionRemarksForm">
                <input type="hidden" name="request_id" id="RejectRequestIdInput">
                <input type="hidden" name="action" value="reject">
                <textarea name="remarks" required class="w-full bg-neutral-50 border border-neutral-200 rounded-xl p-3 text-xs focus:outline-none focus:border-neutral-400 mb-6" rows="4" placeholder="Conflicting records, incorrect spelling, etc."></textarea>
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeRejectionModal()" class="text-xs font-bold text-gray-500">Cancel</button>
                    <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded-xl text-xs font-bold shadow-lg shadow-red-200">Confirm Rejection</button>
                </div>
            </form>
        </div>
    </div>

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
                const mRequestStatus = nodeData.request_status || 'approved';
                const mIsEditable = isTreeOwner && mRequestStatus === 'rejected';

                profilePanelBody.innerHTML = `
                    <div class="profile-row"><span class="profile-label">Name</span><span class="profile-value">${getDisplayName(member)}</span></div>
                    <div class="profile-row"><span class="profile-label">ID</span><span class="profile-value">${String(member.data?.display_id || member.id || '')}</span></div>
                    <div class="profile-row"><span class="profile-label">Gender</span><span class="profile-value">${getDisplayGender(member)}</span></div>
                    <div class="profile-row"><span class="profile-label">Birthday</span><span class="profile-value">${String(member.data?.birthdate || '').trim() || 'Not Registered'}</span></div>
                    ${mIsEditable ? `
                        <div class="mt-4 pt-4 border-t border-neutral-100">
                            <button onclick="triggerEditFromPanel('${memberId}')" class="w-full bg-neutral-500 text-white text-[11px] font-bold uppercase tracking-wider py-2.5 rounded-xl hover:bg-neutral-600 transition shadow-sm flex items-center justify-center gap-2">
                                <i data-lucide="edit-3" class="w-3.5 h-3.5 text-white"></i> Edit Details
                            </button>
                        </div>
                    ` : ''}
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

            const allowedSet = new Set([...directLineageIds, ...siblingIds, ...spouseIds]);

            if (isAdminOrElder) {
                // For Elders/Admins, also include all verified/registered members for context.
                // Ghost ancestors of rejected nodes are filtered out because they aren't in reachable lineage.
                data.forEach(m => {
                    if (!m.data?.is_ghost) allowedSet.add(String(m.id));
                });
            }

            // 1. Filter nodes based on structural allowedSet and verification rules
            const filteredNodes = createChartData().filter(m => {
                const mid = String(m.id);
                if (!allowedSet.has(mid)) return false;
                return true;
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

                // 1. "Even my children" - Hide all descendants
                if (ancestryOnly) m.rels.children = [];

                    // 2. "Spouse lineage" - Clear parents for anyone who isn't a direct ancestor
                    if (!isAdminOrElder && !directLineageIds.has(mid)) m.rels.parents = [];

                    // 3. Keep Siblings simple: hide their spouses to focus on your direct line
                    if (!isAdminOrElder && siblingIds.has(mid)) m.rels.spouses = [];

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
                    // Prevent opening profile if the plus button was clicked
                    if (event.target.closest('.add-parent-action')) return;
                    event.stopPropagation();
    
                    const memberId = String(node?.data?.id || '');
                    if (!memberId) {
                        return;
                    }

                    updateProfilePanel(memberId);
                })
                .setCardInnerHtmlCreator((d) => {
                    const nodeData = d.data.data || {};
                    const fullName = `${nodeData["first name"] || ''} ${nodeData["last name"] || ''}`.trim() || 'Not Registered';
                    // Use original gender if stored (for selected member), otherwise use current gender
                    const genderRaw = String(nodeData._original_gender || nodeData.gender || 'U').toUpperCase();
                    const isFemale = genderRaw === 'FEMALE' || genderRaw === 'F';
                    const isMale = genderRaw === 'MALE' || genderRaw === 'M';
                    const gender = isFemale ? 'F' : (isMale ? 'M' : 'U');
                    const isSelected = selectedMemberId && String(d.data.id) === String(selectedMemberId);
                    
                    // Trust the status decided by the backend service. 
                    // If a node is a ghost without a request, it should arrive as 'pending'.
                    const requestStatus = nodeData.request_status || (nodeData.is_ghost ? 'pending' : 'approved');
                    
                    const isNew = requestStatus === 'pending';
                    const isRejected = requestStatus === 'rejected';
                    const isCancelled = requestStatus === 'cancelled';
                    const isVerified = requestStatus === 'approved';

                    const statusBadge = (isNew && currentRole === 'ip_member')
                        ? `<span class="text-[9px] font-bold text-orange-600 bg-orange-50 px-2 py-0.5 rounded-full uppercase">Pending Verification</span>`
                        : (isRejected)
                        ? `<span class="text-[9px] font-bold text-red-600 bg-red-50 px-2 py-0.5 rounded-full uppercase">Rejected</span>`
                        : (isCancelled)
                        ? `<span class="text-[9px] font-bold text-gray-500 bg-gray-100 px-2 py-0.5 rounded-full uppercase">Link Broken</span>`
                        : (isNew && currentRole !== 'ip_member')
                        ? `<span class="text-[9px] font-bold text-white bg-orange-500 px-2 py-0.5 rounded-full uppercase">NEW</span>`
                        : (isVerified && !nodeData.is_ghost)
                        ? `<span class="text-[9px] font-bold text-emerald-700 bg-emerald-100/50 px-2 py-0.5 rounded-full uppercase">Verified</span>`
                        : '';

                    const remarksHtml = (isRejected && nodeData.rejection_remarks)
                        ? `<div class="mt-1 text-[8px] text-red-500 bg-red-50 px-1.5 py-1 rounded border border-red-100 leading-tight w-full text-center italic" title="${nodeData.rejection_remarks}">"${nodeData.rejection_remarks}"</div>`
                        : '';

                    let cardClass = `node-card relative ${gender === 'F' ? 'node-female' : 'node-male'} ${isSelected ? 'node-selected' : ''}`;
                    if (isNew) cardClass += ' !border-2 !border-orange-400 shadow-[0_0_15px_rgba(249,115,22,0.15)] z-10 ring-2 ring-orange-500/10';
                    if (isRejected || isCancelled) cardClass += ' !border-2 !border-red-400 shadow-[0_0_15px_rgba(239,68,68,0.1)]';

                    // Logic to check if parent slots are available
                    const findMember = (id) => chartData.find(m => String(m.id) === String(id));
                    const existingParents = (d.data.rels?.parents || []).map(pid => findMember(pid)).filter(Boolean);
                    const hasFather = existingParents.some(p => (p.data?._original_gender || p.data?.gender || '').toUpperCase().startsWith('M'));
                    const hasMother = existingParents.some(p => (p.data?._original_gender || p.data?.gender || '').toUpperCase().startsWith('F'));
                    
                    // DEPENDENCY LOCK: Check if any child node attached to this node is still unverified
                    const findChildren = (id) => chartData.filter(m => (m.rels?.parents || []).map(String).includes(String(id)));
                    const childrenNodes = findChildren(d.data.id);
                    const isLocked = childrenNodes.some(c => (c.data?.request_status || 'approved') === 'pending');

                    // Generation Limit Logic: Only show plus if node is within the first 3 generations (Self, Parents, Grandparents)
                    const currentNodeGen = lineageGenMap.get(String(d.data.id)) || 0;
                    const showPlus = isTreeOwner && lineageGenMap.has(String(d.data.id)) && (!hasFather || !hasMother) && (currentNodeGen < 4);
                    
                    const hasSpouse = d.data.rels?.spouses?.length > 0;
                    // Restricted Add Spouse: Only for self, and only if not married
                    const isSelfNode = String(d.data.id) === String(sessionMemberId);
                    const showAddSpouse = isTreeOwner && isSelfNode && sessionMaritalStatus !== 'married' && !hasSpouse;

                    const showEdit = isTreeOwner && isRejected;

                    const nameParts = fullName.split(' ').filter(p => p.length > 0);
                    const initials = nameParts.length >= 2
                        ? (nameParts[0][0] + nameParts[nameParts.length - 1][0]).toUpperCase()
                        : fullName.substring(0, 2).toUpperCase();
                    const avatarClass = gender === 'F' ? 'node-avatar node-avatar-female' : (gender === 'M' ? 'node-avatar node-avatar-male' : 'node-avatar node-avatar-unknown');

                    return `
                        <div class="${cardClass}">
                            ${showPlus && !isRejected && !isCancelled ? `<button class="add-parent-action" data-id="${d.data.id}" data-proposal-id="${nodeData.request_id || ''}" data-name="${fullName}" title="Add Parents">+</button>` : ''}
                            ${showAddSpouse && !isRejected && !isCancelled ? `<button class="add-spouse-action js-add-spouse" data-id="${d.data.id}" data-name="${fullName}" title="Add Spouse"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="20" y1="8" x2="20" y2="14"></line><line x1="23" y1="11" x2="17" y2="11"></line></svg></button>` : ''}
                            ${showEdit ? `<button class="edit-node-action" data-request-id="${nodeData.request_id}" data-name="${fullName}" data-dob="${nodeData.birthdate}" title="Edit and Comply"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path></svg></button>` : ''}
                            <div class="${avatarClass}">${initials}</div>
                            <div class="node-top">
                                <div class="node-name">${fullName}</div>
                            </div>
                            <div class="mt-2.5 mb-2 flex flex-col justify-center items-center min-h-[18px] w-full text-center">
                                ${fullName === 'Unregistered' ? '' : statusBadge}
                                ${remarksHtml}
                            </div>

                            <div class="node-meta mt-2 space-y-1">
                                <div class="flex justify-between items-center text-[9px] bg-white/50 border border-gray-100 p-1.5 rounded-lg">
                                    <span class="text-gray-400 font-bold uppercase tracking-tighter">ID:</span>
                                    ${!nodeData.is_ghost
                                        ? `<span class="text-neutral-700 font-bold tracking-tight">${nodeData.display_id || 'ID Pending'}</span>`
                                        : `<span class="text-gray-400 font-bold italic tracking-tight">Not Registered</span>`
                                    }
                                </div>
                                <div class="flex justify-between items-center text-[9px] bg-white/50 border border-gray-100 p-1.5 rounded-lg">
                                    <span class="text-gray-400 font-bold uppercase tracking-tighter">Birthday:</span>
                                    <span class="text-neutral-700 font-bold tracking-tight">${nodeData.birthdate || 'Not Registered'}</span>
                                </div>
                            </div>

                            <?php if ($currentRole !== 'ip_member'): ?>
                                ${isNew ? `
                                    <div class="flex gap-1 mt-3 pt-3 border-t border-gray-100">
                                        <button 
                                            ${isLocked ? 'disabled' : `onclick="processNode(${nodeData.request_id}, 'reject', '${fullName}')"`} 
                                            class="flex-1 py-1 text-[8px] font-bold uppercase rounded transition ${isLocked ? 'text-gray-300 bg-gray-50 cursor-not-allowed' : 'text-red-500 hover:bg-red-50'}"
                                        >
                                            ${isLocked ? 'Locked' : 'Reject'}
                                        </button>
                                        <button 
                                            onclick="processNode(${nodeData.request_id}, 'approve')" 
                                            ${isLocked ? 'disabled' : ''} 
                                            class="flex-1 py-1 text-[8px] font-bold uppercase rounded transition ${isLocked ? 'bg-gray-100 text-gray-300 cursor-not-allowed' : 'bg-emerald-600 text-white hover:bg-black'}"
                                        >
                                            ${isLocked ? 'Locked' : 'Approve'}
                                        </button>
                                    </div>
                                ` : ''}
                            <?php endif; ?>
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

            window.closeAddParentsModal = () => {
                document.getElementById('AddParentsModal').classList.add('hidden');
                document.getElementById('AddParentsForm').reset();
            };

            window.closeAddSpouseModal = () => {
                document.getElementById('AddSpouseModal').classList.add('hidden');
                document.getElementById('AddSpouseForm').reset();
            };

            document.getElementById('AddSpouseForm').addEventListener('submit', async (e) => {
                e.preventDefault();
                const formData = new FormData(e.target);
                const response = await fetch('backend/add_lineage_spouse.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.ok) { window.location.reload(); }
                else { 
                    alert(result.error || 'Submission failed');
                }
            });

            flatpickr("input[type='date']", {
                dateFormat: "Y-m-d",
                altInput: true,
                altFormat: "F j, Y",
                monthSelectorType: "dropdown",
                yearSelectorType: "static"
            });

            document.getElementById('AddParentsForm').addEventListener('submit', async (e) => {
                e.preventDefault();
                const formData = new FormData(e.target);
                const response = await fetch('backend/add_lineage_parents.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.ok) { window.location.reload(); }
                else { alert(result.error || 'Submission failed'); }
            });

            window.closeEditNodeModal = () => {
                document.getElementById('EditNodeModal').classList.add('hidden');
                document.getElementById('EditNodeForm').reset();
            };

            window.triggerEditFromPanel = (memberId) => {
                const member = getMemberById(memberId);
                if (!member) return;
                const nodeData = member.data || {};
                
                document.getElementById('EditRequestId').value = nodeData.request_id;
                document.getElementById('EditNameInput').value = getDisplayName(member);
                document.getElementById('EditDobInput').value = nodeData.birthdate || '';
                document.getElementById('EditTargetName').textContent = getDisplayName(member);
                document.getElementById('EditNodeModal').classList.remove('hidden');
                if (window.lucide) lucide.createIcons();
            };

            document.getElementById('EditNodeForm').addEventListener('submit', async (e) => {
                e.preventDefault();
                const formData = new FormData(e.target);
                const response = await fetch('backend/edit_lineage_node.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.ok) { window.location.reload(); }
                else { alert(result.error || 'Update failed'); }
            });

            window.processNode = async (requestId, action, name) => {
                if (action === 'reject') {
                    document.getElementById('RejectRequestIdInput').value = requestId;
                    document.getElementById('RejectTargetName').textContent = name;
                    document.getElementById('RejectionRemarksModal').classList.remove('hidden');
                    return;
                }

                const fd = new FormData();
                fd.append('request_id', requestId);
                fd.append('action', 'approve');
                
                const res = await fetch('backend/process_lineage_node.php', { method: 'POST', body: fd });
                const result = await res.json();
                if (result.ok) window.location.reload();
                else alert(result.error || 'Approval failed');
            };

            window.closeRejectionModal = () => {
                document.getElementById('RejectionRemarksModal').classList.add('hidden');
                document.getElementById('RejectionRemarksForm').reset();
            };

            document.getElementById('RejectionRemarksForm').addEventListener('submit', async (e) => {
                e.preventDefault();
                const formData = new FormData(e.target);
                const response = await fetch('backend/process_lineage_node.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.ok) { window.location.reload(); }
                else { alert(result.error || 'Rejection failed'); }
            });

            document.getElementById('FamilyChart').addEventListener('click', (e) => {
                const addBtn = e.target.closest('.add-parent-action');
                const editBtn = e.target.closest('.edit-node-action');
                const spouseBtn = e.target.closest('.add-spouse-action');
                
                if (addBtn) {
                    e.stopPropagation();
                    document.getElementById('ChildIdInput').value = addBtn.dataset.id;
                    document.getElementById('DependsOnInput').value = addBtn.dataset.proposalId || '';
                    document.getElementById('TargetPersonName').textContent = addBtn.dataset.name;
                    document.getElementById('AddParentsModal').classList.remove('hidden');
                    if (window.lucide) lucide.createIcons();
                } else if (editBtn) {
                    e.stopPropagation();
                    document.getElementById('EditRequestId').value = editBtn.dataset.requestId;
                    document.getElementById('EditNameInput').value = editBtn.dataset.name;
                    document.getElementById('EditDobInput').value = editBtn.dataset.dob || '';
                    document.getElementById('EditTargetName').textContent = editBtn.dataset.name;
                    document.getElementById('EditNodeModal').classList.remove('hidden');
                    if (window.lucide) lucide.createIcons();
                } else if (spouseBtn) {
                    e.stopPropagation();
                    document.getElementById('SpousePersonIdInput').value = spouseBtn.dataset.id;
                    document.getElementById('SpouseTargetName').textContent = spouseBtn.dataset.name;
                    document.getElementById('AddSpouseModal').classList.remove('hidden');
                    if (window.lucide) lucide.createIcons();
                }
            });

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