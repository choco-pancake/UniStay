<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

start_app_session();

$pdo = database();

function tenant_id_upload_error(array $file): ?string
{
    if (!isset($file['error']) || is_array($file['error'])) {
        return 'Invalid upload.';
    }
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return 'This ID photo is required.';
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return 'The upload failed. Please try again.';
    }
    if (!in_array($file['type'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return 'Only JPEG, PNG, or WebP images are allowed.';
    }
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return 'The image must be smaller than 5 MB.';
    }
    return null;
}

function tenant_id_upload_save(array $file, string $prefix): string
{
    $uploadDir = __DIR__ . '/uploads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $fileName = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4))
        . ($extension !== '' ? '.' . $extension : '.img');

    if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $fileName)) {
        throw new RuntimeException('Could not save the uploaded ID photo.');
    }

    return 'uploads/' . $fileName;
}

$user_id = current_tenant_id();

// Where to go after the onboarding is done. Visitors who signed in with
// Google continue to the tenant map, everyone else to the dashboard.
$next = (string) ($_GET['next'] ?? 'dashboard');
$nextPage = $next === 'map' ? 'map.php' : 'dashboard.php';

// Already finished onboarding? Straight on.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && tenant_profile_complete($pdo, $user_id)) {
    header('Location: ' . $nextPage);
    exit;
}

$genderOptions          = ['Male', 'Female', 'Other'];
$relationshipOptions    = ['Parent', 'Guardian', 'Sibling', 'Relative', 'Friend', 'Other'];
$errors                 = [];
$form = [
    'first_name'             => '',
    'last_name'              => '',
    'phone_number'           => '',
    'date_of_birth'          => '',
    'gender'                 => '',
    'school'                 => '',
    'emergency_name'         => '',
    'emergency_relationship' => '',
    'emergency_phone'        => '',
];

// Prefill anything already saved (e.g. a previous partial attempt).
if ($pdo) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$user_id]);
        $row = $stmt->fetch();
        if ($row) {
            foreach ($form as $key => $_) {
                if (!empty($row[$key])) {
                    $form[$key] = $row[$key];
                }
            }
        }
    } catch (PDOException $e) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $key => $_) {
        $form[$key] = trim($_POST[$key] ?? '');
    }

    if ($form['first_name'] === '') {
        $errors[] = 'First name is required.';
    }


    if ($form['phone_number'] === '') {
        $errors[] = 'Phone number is required.';
    } elseif (!preg_match('/^09[0-9]{9}$/', $form['phone_number'])) {
        $errors[] = 'Phone number must look like 09XXXXXXXXX.';
    }

    if ($form['date_of_birth'] === '') {
        $errors[] = 'Birthdate is required.';
    } elseif (strtotime($form['date_of_birth']) === false || $form['date_of_birth'] > date('Y-m-d')) {
        $errors[] = 'Please enter a valid birthdate.';
    }

    if (!in_array($form['gender'], $genderOptions, true)) {
        $errors[] = 'Gender is required.';
    }

    if ($form['school'] === '') {
        $errors[] = 'School is required.';
    }

    if ($form['emergency_name'] === '') {
        $errors[] = 'Emergency contact name is required.';
    }

    if (!in_array($form['emergency_relationship'], $relationshipOptions, true)) {
        $errors[] = 'Relationship to the emergency contact is required.';
    }

    if ($form['emergency_phone'] === '') {
        $errors[] = 'Emergency contact phone number is required.';
    } elseif (!preg_match('/^09[0-9]{9}$/', $form['emergency_phone'])) {
        $errors[] = 'Emergency contact phone number must look like 09XXXXXXXXX.';
    }

    $schoolIdError = isset($_FILES['school_id']) ? tenant_id_upload_error($_FILES['school_id']) : 'This ID photo is required.';
    $govIdError    = isset($_FILES['gov_id']) ? tenant_id_upload_error($_FILES['gov_id']) : 'This ID photo is required.';
    if ($schoolIdError) {
        $errors[] = 'School ID: ' . $schoolIdError;
    }
    if ($govIdError) {
        $errors[] = 'Government ID: ' . $govIdError;
    }

    if (!$errors) {
        if (!$pdo) {
            $errors[] = 'Database connection is unavailable. Please try again later.';
        } else {
            $schoolIdPath = tenant_id_upload_save($_FILES['school_id'], 'school_id');
            $govIdPath    = tenant_id_upload_save($_FILES['gov_id'], 'gov_id');

            $stmt = $pdo->prepare('UPDATE users SET
                first_name = ?, last_name = ?,
                phone_number = ?, date_of_birth = ?, gender = ?, school = ?,
                emergency_name = ?, emergency_relationship = ?, emergency_phone = ?,
                school_id_file = ?, gov_id_file = ?, profile_completed = 1
                WHERE id = ?');
            $stmt->execute([
                $form['first_name'], $form['last_name'] !== '' ? $form['last_name'] : null,
                $form['phone_number'], $form['date_of_birth'], $form['gender'], $form['school'],
                $form['emergency_name'], $form['emergency_relationship'], $form['emergency_phone'],
                $schoolIdPath, $govIdPath, $user_id,
            ]);

            if (isset($_SESSION['user'])) {
                $_SESSION['user']['name'] = trim($form['first_name'] . ' ' . $form['last_name']) ?: $form['first_name'];
            }

            header('Location: ' . $nextPage);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UniStay - Complete Your Registration</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/register.css">
    <style>
        body {
            background: radial-gradient(ellipse at 18% 10%, var(--bg-gradient-start) 0%, var(--bg-gradient-end) 62%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .complete-container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
        }
        .setup-card {
            background: var(--card-bg);
            width: 100%;
            max-width: 980px;
            border-radius: 20px;
            box-shadow: 4px 7px 6px rgba(0,0,0,0.2);
            padding: 32px 38px 28px;
            display: flex;
            flex-direction: column;
            gap: 18px;
        }
        .setup-header {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .setup-header img {
            width: 54px;
            height: 54px;
        }
        .setup-header-text h1 {
            font-family: 'Fraunces', Georgia, serif;
            font-size: clamp(26px, 4vw, 34px);
            color: var(--text-primary);
        }
        .setup-header-text p {
            color: var(--text-secondary);
            font-size: clamp(14px, 2vw, 17px);
        }
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 14px;
        }
        .alert-error ul {
            margin: 8px 0 0 18px;
            display: grid;
            gap: 4px;
        }
        .step-indicator {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            position: relative;
        }
        .step-indicator::before {
            content: '';
            position: absolute;
            left: 0;
            right: 0;
            top: 50%;
            height: 2px;
            background: linear-gradient(90deg, rgba(129,76,55,0.25), rgba(129,76,55,0.12));
            transform: translateY(-50%);
        }
        .step {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            text-align: center;
            z-index: 1;
        }
        .step-dot {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: #f6f3ef;
            border: 1px solid rgba(129,76,55,0.25);
            color: var(--text-secondary);
            font-weight: 600;
        }
        .step.is-active .step-dot {
            background: var(--brand-brown);
            color: #fff;
            box-shadow: 0 10px 24px rgba(129,76,55,0.18);
        }
        .step.is-complete .step-dot {
            background: rgba(129,76,55,0.12);
            color: var(--brand-brown);
        }
        .step-label {
            font-size: 13px;
            color: var(--text-secondary);
        }
        .step.is-active .step-label {
            color: var(--text-primary);
            font-weight: 600;
        }
        .step-panels {
            display: grid;
        }
        .step-panel {
            display: none;
            flex-direction: column;
            gap: 22px;
        }
        .step-panel.is-active {
            display: flex;
        }
        .panel-header h2 {
            font-family: 'Fraunces', Georgia, serif;
            font-size: clamp(22px, 3vw, 28px);
        }
        .panel-header p {
            color: var(--text-secondary);
            margin-top: 4px;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }
        .form-field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .form-field.full {
            grid-column: 1 / -1;
        }
        .form-field label {
            font-weight: 600;
            color: var(--text-primary);
        }
        .form-field label .req {
            color: var(--required-color);
        }
        .form-field input,
        .form-field select {
            width: 100%;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            background: var(--input-bg);
            padding: 12px 14px;
            font-size: 15px;
            color: var(--text-primary);
        }
        .form-field input:focus,
        .form-field select:focus {
            outline: none;
            border-color: rgba(129,76,55,0.8);
            box-shadow: 0 0 0 4px rgba(129,76,55,0.08);
        }
        .upload-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }
        .upload-card {
            border-radius: 16px;
            border: 1px dashed rgba(129,76,55,0.4);
            background: linear-gradient(180deg, rgba(248,241,223,0.4) 0%, rgba(255,255,255,0.9) 100%);
            padding: 20px;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            cursor: pointer;
        }
        .upload-card.is-dragover {
            border-style: solid;
            border-color: var(--brand-brown);
            box-shadow: 0 12px 28px rgba(129,76,55,0.12);
        }
        .upload-icon {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: rgba(129,76,55,0.12);
            color: var(--brand-brown);
        }
        .upload-text {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .upload-text strong {
            color: var(--text-primary);
        }
        .upload-text span {
            color: var(--text-secondary);
            font-size: 14px;
        }
        .upload-filename {
            font-size: 14px;
            color: var(--brand-brown);
            font-weight: 600;
        }
        .upload-hint {
            font-size: 13px;
            color: var(--text-secondary);
        }
        .form-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            border-top: 1px solid rgba(221,201,189,0.6);
            padding-top: 18px;
        }
        .btn {
            border-radius: 999px;
            border: 1px solid transparent;
            padding: 12px 26px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-ghost {
            background: transparent;
            color: var(--text-secondary);
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--brand-brown) 0%, var(--brand-brown-hover) 100%);
            color: #fff;
            box-shadow: 0 12px 24px rgba(129,76,55,0.18);
        }
        .btn-primary:hover {
            filter: brightness(0.98);
        }
        @media (max-width: 820px) {
            .setup-card {
                padding: 26px 22px 24px;
            }
            .form-grid,
            .upload-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <a class="logo-container" href="../index.php" aria-label="UniStay home">
            <img class="logo-icon" src="../assets/images/logo.png" alt="logo">
            <span class="logo-text">UniStay</span>
        </a>
        <a href="dashboard.php" style="color: var(--text-secondary); font-size: 14px; font-weight: 600; text-decoration: none; margin-left: auto;">Back to dashboard &rarr;</a>
    </header>

    <main class="complete-container">
        <div class="setup-card">
            <div class="setup-header">
                <img src="../assets/images/logo.png" alt="UniStay logo">
                <div class="setup-header-text">
                    <h1>Complete your tenant profile</h1>
                    <p>Tell us a little about you so we can match you to the right rooms.</p>
                </div>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert-error">
                    <strong>Please fix the following:</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="step-indicator">
                <div class="step is-active" data-step="1">
                    <span class="step-dot">1</span>
                    <span class="step-label">Contact details</span>
                </div>
                <div class="step" data-step="2">
                    <span class="step-dot">2</span>
                    <span class="step-label">Emergency contact</span>
                </div>
                <div class="step" data-step="3">
                    <span class="step-dot">3</span>
                    <span class="step-label">ID verification</span>
                </div>
            </div>

            <form action="complete-profile.php?next=<?= rawurlencode($next) ?>" method="POST" enctype="multipart/form-data" id="completeProfileForm" novalidate>
                <div class="step-panels">
                    <section class="step-panel is-active" data-panel="1">
                        <header class="panel-header">
                            <h2>Contact details</h2>
                            <p>How we can reach you about bookings and updates.</p>
                        </header>
                        <div class="form-grid">
                            <div class="form-field">
                                <label>First Name <span class="req">*</span></label>
                                <input type="text" name="first_name" id="first_name" placeholder="First Name" value="<?= htmlspecialchars($form['first_name']) ?>" required>
                            </div>
                            <div class="form-field">
                                <label>Last Name <span style="font-weight: normal; color: #78716c; font-size: 12px;">(optional)</span></label>
                                <input type="text" name="last_name" id="last_name" placeholder="Last Name" value="<?= htmlspecialchars($form['last_name']) ?>">
                            </div>
                            <div class="form-field">
                                <label>Phone Number <span class="req">*</span></label>
                                <input type="text" name="phone_number" id="phone_number" maxlength="11" placeholder="09XXXXXXXXX" value="<?= htmlspecialchars($form['phone_number']) ?>" required>
                            </div>
                            <div class="form-field">
                                <label>Birthdate <span class="req">*</span></label>
                                <input type="date" name="date_of_birth" max="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($form['date_of_birth']) ?>" required>
                            </div>
                            <div class="form-field">
                                <label>Gender <span class="req">*</span></label>
                                <select name="gender" required>
                                    <option value="" disabled <?= $form['gender'] === '' ? 'selected' : '' ?>>Select gender</option>
                                    <?php foreach ($genderOptions as $opt): ?>
                                        <option value="<?= $opt ?>" <?= $form['gender'] === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-field">
                                <label>School <span class="req">*</span></label>
                                <input type="text" name="school" placeholder="e.g. Universidad de Dagupan" value="<?= htmlspecialchars($form['school']) ?>" required>
                            </div>
                        </div>
                    </section>

                    <section class="step-panel" data-panel="2">
                        <header class="panel-header">
                            <h2>Emergency contact</h2>
                            <p>Just in case we need to reach someone on your behalf.</p>
                        </header>
                        <div class="form-grid">
                            <div class="form-field full">
                                <label>Contact Name <span class="req">*</span></label>
                                <input type="text" name="emergency_name" placeholder="Full name" value="<?= htmlspecialchars($form['emergency_name']) ?>" required>
                            </div>
                            <div class="form-field">
                                <label>Relationship <span class="req">*</span></label>
                                <select name="emergency_relationship" required>
                                    <option value="" disabled <?= $form['emergency_relationship'] === '' ? 'selected' : '' ?>>Select relationship</option>
                                    <?php foreach ($relationshipOptions as $rel): ?>
                                        <option value="<?= $rel ?>" <?= $form['emergency_relationship'] === $rel ? 'selected' : '' ?>><?= $rel ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-field">
                                <label>Phone Number <span class="req">*</span></label>
                                <input type="text" name="emergency_phone" id="emergency_phone" maxlength="11" placeholder="09XXXXXXXXX" value="<?= htmlspecialchars($form['emergency_phone']) ?>" required>
                            </div>
                        </div>
                    </section>

                    <section class="step-panel" data-panel="3">
                        <header class="panel-header">
                            <h2>ID verification</h2>
                            <p>Upload clear photos of your IDs for verification.</p>
                        </header>
                        <div class="upload-grid">
                            <label class="upload-card" for="school_id_input" data-dropzone="school_id">
                                <div class="upload-icon">
                                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 3v12m0 0 4-4m-4 4-4-4"/><path d="M5 21h14"/></svg>
                                </div>
                                <div class="upload-text">
                                    <strong>School ID</strong>
                                    <span>Click to upload or drag and drop</span>
                                </div>
                                <span class="upload-filename" data-filename="school_id">No file chosen</span>
                                <span class="upload-hint">JPG, PNG, or WebP · max 5 MB</span>
                                <input type="file" id="school_id_input" name="school_id" accept="image/jpeg,image/png,image/webp" hidden>
                            </label>
                            <label class="upload-card" for="gov_id_input" data-dropzone="gov_id">
                                <div class="upload-icon">
                                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 3v12m0 0 4-4m-4 4-4-4"/><path d="M5 21h14"/></svg>
                                </div>
                                <div class="upload-text">
                                    <strong>Government ID</strong>
                                    <span>Click to upload or drag and drop</span>
                                </div>
                                <span class="upload-filename" data-filename="gov_id">No file chosen</span>
                                <span class="upload-hint">JPG, PNG, or WebP · max 5 MB</span>
                                <input type="file" id="gov_id_input" name="gov_id" accept="image/jpeg,image/png,image/webp" hidden>
                            </label>
                        </div>
                    </section>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-ghost" id="prevStep" hidden>Back</button>
                    <button type="button" class="btn btn-primary" id="nextStep">Next</button>
                    <button type="submit" class="btn btn-primary" id="submitStep" hidden><?= $next === 'map' ? 'Complete Registration &amp; Find a Home' : 'Complete Registration &amp; Go to Dashboard' ?></button>
                </div>
            </form>
        </div>
    </main>

    <script>
        const steps = Array.from(document.querySelectorAll('.step'));
        const panels = Array.from(document.querySelectorAll('.step-panel'));
        const nextBtn = document.getElementById('nextStep');
        const prevBtn = document.getElementById('prevStep');
        const submitBtn = document.getElementById('submitStep');
        const form = document.getElementById('completeProfileForm');
        let currentStep = 1;

        function updateSteps() {
            steps.forEach((step, index) => {
                step.classList.toggle('is-active', index + 1 === currentStep);
                step.classList.toggle('is-complete', index + 1 < currentStep);
            });
            panels.forEach((panel) => {
                panel.classList.toggle('is-active', Number(panel.dataset.panel) === currentStep);
            });
            prevBtn.hidden = currentStep === 1;
            nextBtn.hidden = currentStep === 3;
            submitBtn.hidden = currentStep !== 3;
        }

        function validateCurrentStep() {
            const panel = panels.find((p) => Number(p.dataset.panel) === currentStep);
            if (!panel) return true;
            const fields = Array.from(panel.querySelectorAll('input[required], select[required]'));
            for (const field of fields) {
                if (field.type === 'file') {
                    if (!field.files || field.files.length === 0) {
                        field.focus();
                        return false;
                    }
                    const file = field.files[0];
                    const okType = ['image/jpeg', 'image/png', 'image/webp'].includes(file.type);
                    if (!okType || file.size > 5 * 1024 * 1024) {
                        return false;
                    }
                    continue;
                }
                if (field.tagName === 'SELECT') {
                    if (field.value === '' || field.selectedIndex === 0) {
                        field.focus();
                        return false;
                    }
                    continue;
                }
                if (field.value.trim() === '') {
                    field.focus();
                    return false;
                }
                if (field.name === 'phone_number' || field.name === 'emergency_phone') {
                    if (!/^09[0-9]{9}$/.test(field.value.trim())) {
                        field.focus();
                        return false;
                    }
                }
            }
            return true;
        }

        nextBtn.addEventListener('click', () => {
            if (!validateCurrentStep()) {
                alert('Please complete all required fields in this step.');
                return;
            }
            currentStep = Math.min(3, currentStep + 1);
            updateSteps();
        });

        prevBtn.addEventListener('click', () => {
            currentStep = Math.max(1, currentStep - 1);
            updateSteps();
        });

        ['phone_number', 'emergency_phone'].forEach((id) => {
            const input = document.getElementById(id);
            if (input) {
                input.addEventListener('input', function () {
                    this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11);
                });
            }
        });

        const fileInputs = [
            { input: document.getElementById('school_id_input'), name: 'school_id' },
            { input: document.getElementById('gov_id_input'), name: 'gov_id' }
        ];

        fileInputs.forEach(({ input, name }) => {
            if (!input) return;
            const label = document.querySelector(`[data-filename="${name}"]`);
            const card = document.querySelector(`[data-dropzone="${name}"]`);
            input.addEventListener('change', () => {
                if (input.files && input.files[0]) {
                    label.textContent = input.files[0].name;
                } else {
                    label.textContent = 'No file chosen';
                }
            });
            if (card) {
                card.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    card.classList.add('is-dragover');
                });
                card.addEventListener('dragleave', () => card.classList.remove('is-dragover'));
                card.addEventListener('drop', (e) => {
                    e.preventDefault();
                    card.classList.remove('is-dragover');
                    if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                        input.files = e.dataTransfer.files;
                        label.textContent = e.dataTransfer.files[0].name;
                    }
                });
            }
        });

        form.addEventListener('submit', (e) => {
            for (let i = 1; i <= 3; i += 1) {
                currentStep = i;
                updateSteps();
                if (!validateCurrentStep()) {
                    e.preventDefault();
                    alert('Please complete all required fields before submitting.');
                    return;
                }
            }
        });

        updateSteps();
    </script>
</body>
</html>
