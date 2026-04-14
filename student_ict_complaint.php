<?php
ob_start();
session_start();

if (!isset($_SESSION["student_loggedin"]) || $_SESSION["student_loggedin"] !== true) {
    header("location: student_login.php");
    exit;
}

require_once "config.php";

// Load decision tree
$tree_file = __DIR__ . '/data/complaint_tree.json';
$tree_json = file_exists($tree_file) ? file_get_contents($tree_file) : '{}';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ICT Complaint - TSU ICT Help Desk</title>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<style>
body { background:#f4f7fb; font-family:'Segoe UI',sans-serif; }
.wizard-wrap { max-width:720px; margin:2rem auto; padding:0 1rem; }
.wizard-card { background:#fff; border-radius:14px; box-shadow:0 4px 20px rgba(30,60,114,.1); overflow:hidden; }
.wizard-header { background:linear-gradient(135deg,#1e3c72,#2a5298); color:#fff; padding:1.5rem 2rem; }
.wizard-header h4 { margin:0; font-weight:700; }
.wizard-header p  { margin:.25rem 0 0; opacity:.85; font-size:.9rem; }
.wizard-body { padding:1.75rem 2rem; }

/* Progress */
.progress-bar-wrap { margin-bottom:1.5rem; }
.progress { height:6px; border-radius:3px; background:#e9ecef; }
.progress-bar { background:linear-gradient(90deg,#1e3c72,#4a90e2); border-radius:3px; transition:width .4s; }
.step-label { font-size:.78rem; color:#6c757d; margin-bottom:.4rem; }

/* Breadcrumb trail */
.trail { display:flex; flex-wrap:wrap; gap:.4rem; margin-bottom:1.25rem; }
.trail-item { background:#e8f0fe; color:#1e3c72; border-radius:20px; padding:.2rem .75rem; font-size:.78rem; display:flex; align-items:center; gap:.3rem; }
.trail-item .back-btn { cursor:pointer; opacity:.6; font-size:.7rem; }
.trail-item .back-btn:hover { opacity:1; }

/* Question */
.question-text { font-size:1.1rem; font-weight:600; color:#1e3c72; margin-bottom:1.25rem; }

/* Option buttons */
.options-grid { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; }
@media(max-width:500px){ .options-grid { grid-template-columns:1fr; } }
.opt-btn {
    background:#fff; border:2px solid #dee2e6; border-radius:10px;
    padding:.85rem 1rem; text-align:left; cursor:pointer;
    transition:border-color .15s, background .15s, transform .1s;
    font-size:.88rem; color:#333; line-height:1.4;
    display:flex; align-items:flex-start; gap:.6rem;
}
.opt-btn:hover { border-color:#1e3c72; background:#f0f4ff; transform:translateY(-1px); }
.opt-btn.selected { border-color:#1e3c72; background:#e8f0fe; }
.opt-btn i { color:#1e3c72; margin-top:.1rem; flex-shrink:0; }

/* Auto-response box */
.response-box { background:#f0f7ff; border-left:4px solid #1e3c72; border-radius:8px; padding:1.25rem 1.5rem; margin-bottom:1.25rem; }
.response-box h6 { color:#1e3c72; font-weight:700; margin-bottom:.5rem; }
.response-box p  { margin:0; color:#333; font-size:.92rem; line-height:1.6; }

/* Extra fields */
.extra-fields { background:#f8f9fa; border-radius:8px; padding:1.25rem; margin-bottom:1.25rem; }
.extra-fields label { font-size:.82rem; font-weight:600; color:#495057; }
.extra-fields .form-control { font-size:.88rem; }

/* Action buttons */
.action-row { display:flex; gap:.75rem; flex-wrap:wrap; margin-top:1rem; }
.btn-primary-custom { background:linear-gradient(135deg,#1e3c72,#2a5298); color:#fff; border:none; border-radius:8px; padding:.6rem 1.4rem; font-size:.88rem; font-weight:600; cursor:pointer; transition:opacity .15s; }
.btn-primary-custom:hover { opacity:.9; }
.btn-outline-custom { background:#fff; color:#1e3c72; border:2px solid #1e3c72; border-radius:8px; padding:.55rem 1.2rem; font-size:.88rem; font-weight:600; cursor:pointer; transition:background .15s; }
.btn-outline-custom:hover { background:#e8f0fe; }
.btn-danger-custom { background:#dc3545; color:#fff; border:none; border-radius:8px; padding:.6rem 1.4rem; font-size:.88rem; font-weight:600; cursor:pointer; }

/* Success screen */
.success-screen { text-align:center; padding:2rem 1rem; }
.success-icon { font-size:3.5rem; color:#28a745; margin-bottom:1rem; }
.success-screen h5 { color:#1e3c72; font-weight:700; }

/* AI badge */
.ai-badge { display:inline-flex; align-items:center; gap:.3rem; background:#fff3cd; color:#856404; border-radius:20px; padding:.2rem .75rem; font-size:.75rem; font-weight:600; margin-bottom:1rem; }

/* Spinner */
.spinner-sm { width:18px; height:18px; border:2px solid #fff; border-top-color:transparent; border-radius:50%; animation:spin .6s linear infinite; display:inline-block; vertical-align:middle; margin-right:.4rem; }
@keyframes spin { to { transform:rotate(360deg); } }
</style>
</head>
<body>

<!-- Navbar -->
<nav style="background:#1e3c72;padding:.75rem 1rem;display:flex;align-items:center;gap:1rem;position:sticky;top:0;z-index:100;box-shadow:0 1px 6px rgba(0,0,0,.2)">
    <a href="student_dashboard.php" style="color:#fff;text-decoration:none;font-size:.85rem;opacity:.85">
        <i class="fas fa-arrow-left mr-1"></i> Back to Dashboard
    </a>
    <span style="color:rgba(255,255,255,.3)">|</span>
    <span style="color:#fff;font-weight:600;font-size:.9rem">ICT Complaint Wizard</span>
</nav>

<div class="wizard-wrap">
    <div class="wizard-card">
        <div class="wizard-header">
            <h4><i class="fas fa-headset mr-2"></i>Lodge an ICT Complaint</h4>
            <p>Answer a few questions and we'll route your complaint to the right team.</p>
        </div>
        <div class="wizard-body">
            <!-- Progress -->
            <div class="progress-bar-wrap">
                <div class="step-label" id="stepLabel">Step 1 of ?</div>
                <div class="progress"><div class="progress-bar" id="progressBar" style="width:10%"></div></div>
            </div>

            <!-- Breadcrumb trail -->
            <div class="trail" id="trail"></div>

            <!-- Dynamic content -->
            <div id="wizardContent"></div>
        </div>
    </div>
</div>

<!-- Puter.js for AI classification -->
<script src="https://js.puter.com/v2/"></script>
<script>
const TREE = <?php echo $tree_json; ?>;
const STUDENT_ID = <?php echo (int)$_SESSION['student_id']; ?>;

// ── State ──────────────────────────────────────────────────
let state = {
    path: [],          // array of node objects visited
    pathLabels: [],    // human-readable labels
    currentNode: TREE,
    extraFields: {},
    description: '',
    depth: 0,
};

// ── Boot ──────────────────────────────────────────────────
renderNode(TREE);

// ── Render ────────────────────────────────────────────────
function renderNode(node) {
    state.currentNode = node;
    updateProgress();
    updateTrail();

    const c = document.getElementById('wizardContent');

    if (node.type === 'leaf') {
        renderLeaf(node, c);
    } else {
        renderQuestion(node, c);
    }
}

function renderQuestion(node, c) {
    let html = `<div class="question-text">${esc(node.label)}</div>
                <div class="options-grid">`;

    (node.children || []).forEach(child => {
        const icon = iconFor(child);
        html += `<button class="opt-btn" onclick="selectOption(${JSON.stringify(JSON.stringify(child))})">
                    <i class="fas ${icon}"></i>
                    <span>${esc(child.label)}</span>
                 </button>`;
    });

    html += `</div>`;

    if (state.path.length > 0) {
        html += `<div class="action-row">
                    <button class="btn-outline-custom" onclick="goBack()"><i class="fas fa-arrow-left mr-1"></i>Back</button>
                 </div>`;
    }

    c.innerHTML = html;
}

function renderLeaf(node, c) {
    const isAutoResponse = node.actionType === 'auto_response';
    const isFreeText     = node.actionType === 'free_text';

    let html = '';

    // Show auto-response if applicable
    if (isAutoResponse && node.responseText) {
        html += `<div class="response-box">
                    <h6><i class="fas fa-info-circle mr-1"></i>Suggested Resolution</h6>
                    <p>${esc(node.responseText)}</p>
                 </div>`;
    }

    // AI badge for free_text
    if (isFreeText && node.aiClassificationEnabled) {
        html += `<div class="ai-badge"><i class="fas fa-robot"></i> AI-assisted classification</div>`;
    }

    // Free text description
    if (isFreeText || node.actionType === 'escalate') {
        html += `<div class="extra-fields">
                    <div class="form-group mb-2">
                        <label>Describe your issue <span class="text-muted">(optional but helpful)</span></label>
                        <textarea id="descField" class="form-control" rows="3" placeholder="Add any extra details...">${esc(state.description)}</textarea>
                    </div>
                 </div>`;
    }

    // Required extra fields
    const fields = buildExtraFields(node.requiredFields || []);
    if (fields) {
        html += `<div class="extra-fields">${fields}</div>`;
    }

    // Action buttons
    html += `<div class="action-row">`;

    if (isAutoResponse) {
        html += `<button class="btn-primary-custom" onclick="submitComplaint(false)">
                    <i class="fas fa-check mr-1"></i>This resolved my issue
                 </button>`;
        if (node.allowEscalation) {
            html += `<button class="btn-outline-custom" onclick="submitComplaint(true)">
                        <i class="fas fa-user-headset mr-1"></i>Still need help — escalate to ICT
                     </button>`;
        }
    } else {
        html += `<button class="btn-primary-custom" onclick="submitComplaint(true)">
                    <i class="fas fa-paper-plane mr-1"></i>Submit Complaint
                 </button>`;
    }

    html += `<button class="btn-outline-custom" onclick="goBack()"><i class="fas fa-arrow-left mr-1"></i>Back</button>`;
    html += `</div>`;

    c.innerHTML = html;
}

function buildExtraFields(fields) {
    const skip = ['student_id']; // auto-filled
    const labels = {
        registered_email: 'Registered Email',
        matric_number: 'Matric Number',
        jamb_number: 'JAMB Number',
        course_code: 'Course Code',
        current_session: 'Current Session',
        target_session: 'Target Session',
        correct_level: 'Correct Level',
        total_units: 'Total Units',
        transaction_id: 'Transaction ID',
        rrr: 'RRR (Remita Retrieval Reference)',
        expected_grade: 'Expected Grade',
        department: 'Department',
        complaint_description: 'Complaint Description',
    };

    const visible = fields.filter(f => !skip.includes(f));
    if (!visible.length) return '';

    return visible.map(f => {
        const label = labels[f] || f.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        const val = esc(state.extraFields[f] || '');
        return `<div class="form-group mb-2">
                    <label>${label}</label>
                    <input type="text" class="form-control" id="ef_${f}" value="${val}"
                           placeholder="${label}" oninput="state.extraFields['${f}']=this.value">
                </div>`;
    }).join('');
}

// ── Navigation ────────────────────────────────────────────
function selectOption(nodeJson) {
    const node = JSON.parse(nodeJson);
    state.path.push(state.currentNode);
    state.pathLabels.push(node.label);
    state.depth++;
    renderNode(node);
}

function goBack() {
    if (!state.path.length) return;
    state.currentNode = state.path.pop();
    state.pathLabels.pop();
    state.depth--;
    renderNode(state.currentNode);
}

// ── Submit ────────────────────────────────────────────────
async function submitComplaint(escalated) {
    const node = state.currentNode;

    // Collect description
    const descEl = document.getElementById('descField');
    if (descEl) state.description = descEl.value.trim();

    // AI classification for free_text
    if (node.aiClassificationEnabled && state.description && typeof puter !== 'undefined') {
        await classifyWithAI(node);
    }

    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = `<span class="spinner-sm"></span>Submitting…`;

    const payload = {
        node_id:       node.id,
        node_label:    node.label,
        category:      getCategoryLabel(),
        path_labels:   state.pathLabels,
        action_type:   node.actionType,
        auto_response: node.responseText || '',
        escalated:     escalated ? 1 : 0,
        extra_fields:  state.extraFields,
        description:   state.description,
    };

    try {
        const res = await fetch('api/ict_complaint_submit.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();

        if (data.success) {
            showSuccess(data, escalated, node);
        } else {
            alert('Error: ' + (data.message || 'Could not submit complaint'));
            btn.disabled = false;
            btn.innerHTML = escalated
                ? '<i class="fas fa-paper-plane mr-1"></i>Submit Complaint'
                : '<i class="fas fa-check mr-1"></i>This resolved my issue';
        }
    } catch (e) {
        alert('Network error. Please try again.');
        btn.disabled = false;
    }
}

async function classifyWithAI(node) {
    try {
        const targets = (node.aiClassificationTargets || []).join(', ');
        const prompt = `A student submitted this complaint: "${state.description}". 
Classify it into one of these categories: ${targets}. 
Reply with only the category ID, nothing else.`;
        const result = await puter.ai.chat(prompt);
        const cat = (result?.message?.content || result || '').trim();
        if (cat) state.extraFields['ai_category'] = cat;
    } catch (e) {
        // AI unavailable — continue without classification
    }
}

function showSuccess(data, escalated, node) {
    const c = document.getElementById('wizardContent');
    document.getElementById('trail').innerHTML = '';
    document.getElementById('progressBar').style.width = '100%';
    document.getElementById('stepLabel').textContent = 'Complete';

    let msg = escalated
        ? 'Your complaint has been submitted and will be reviewed by the ICT team. You will receive a notification when there is an update.'
        : 'Your complaint has been marked as resolved. If the issue persists, you can re-submit.';

    let responseHtml = '';
    if (!escalated && data.auto_response) {
        responseHtml = `<div class="response-box mt-3">
                            <h6><i class="fas fa-info-circle mr-1"></i>Resolution</h6>
                            <p>${esc(data.auto_response)}</p>
                        </div>`;
    }

    c.innerHTML = `<div class="success-screen">
        <div class="success-icon"><i class="fas fa-check-circle"></i></div>
        <h5>${escalated ? 'Complaint Submitted' : 'Issue Resolved'}</h5>
        <p class="text-muted">${msg}</p>
        ${responseHtml}
        <div class="action-row justify-content-center mt-3">
            <a href="student_dashboard.php" class="btn-primary-custom" style="text-decoration:none">
                <i class="fas fa-home mr-1"></i>Back to Dashboard
            </a>
            <button class="btn-outline-custom" onclick="resetWizard()">
                <i class="fas fa-plus mr-1"></i>New Complaint
            </button>
        </div>
    </div>`;
}

function resetWizard() {
    state = { path:[], pathLabels:[], currentNode:TREE, extraFields:{}, description:'', depth:0 };
    renderNode(TREE);
}

// ── Helpers ───────────────────────────────────────────────
function updateProgress() {
    const depth = state.depth;
    const pct = Math.min(10 + depth * 15, 90);
    document.getElementById('progressBar').style.width = pct + '%';
    document.getElementById('stepLabel').textContent = depth === 0 ? 'Step 1' : `Step ${depth + 1}`;
}

function updateTrail() {
    const trail = document.getElementById('trail');
    if (!state.pathLabels.length) { trail.innerHTML = ''; return; }
    trail.innerHTML = state.pathLabels.map((lbl, i) =>
        `<div class="trail-item">
            <span>${esc(lbl)}</span>
         </div>`
    ).join('');
}

function getCategoryLabel() {
    // First item in path is the category
    return state.pathLabels[0] || state.currentNode.label || '';
}

function iconFor(node) {
    const map = {
        account_login_profile: 'fa-user-lock',
        portal_session_level_status: 'fa-calendar-alt',
        course_registration_carryovers: 'fa-book',
        payments_fees: 'fa-credit-card',
        printing_documents: 'fa-print',
        results_grades_records: 'fa-chart-bar',
        biometrics_id_transcript: 'fa-id-card',
        staff_specific_admin: 'fa-users-cog',
        other_not_listed: 'fa-question-circle',
    };
    return map[node.id] || 'fa-chevron-right';
}

function esc(str) {
    if (!str) return '';
    const d = document.createElement('div');
    d.textContent = String(str);
    return d.innerHTML;
}
</script>
</body>
</html>
