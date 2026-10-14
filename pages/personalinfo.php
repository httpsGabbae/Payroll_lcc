<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('superadmin', 'hr');
require_once __DIR__ . '/../includes/layout.php';

$editId = (int)($_GET['edit'] ?? 0);
$employee = null;
$message = '';
$error = '';

if ($editId > 0) {
    $stmt = $conn->prepare('SELECT * FROM employees WHERE employee_id=? LIMIT 1');
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $employee = $stmt->get_result()->fetch_assoc();
    if (!$employee) {
        header('Location: employees.php?error=notfound');
        exit;
    }
    if ($employee['employment_status'] === 'Archived' && !is_superadmin()) {
        http_response_code(403);
        exit('Archived employee records are read-only for HR.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = (int)($_POST['employee_id'] ?? 0);
    $first = trim($_POST['first_name'] ?? '');
    $middle = trim($_POST['middle_name'] ?? '');
    $last = trim($_POST['last_name'] ?? '');
    $gender = $_POST['gender'] ?? 'Other';
    $birth = trim($_POST['birth_date'] ?? '') ?: null;
    $email = trim($_POST['email'] ?? '');
    $contact = trim($_POST['contact_number'] ?? '');
    $civil = trim($_POST['civil_status'] ?? '');
    $nationality = trim($_POST['nationality'] ?? '');
    $religion = trim($_POST['religion'] ?? '');
    $permanent = trim($_POST['permanent_address'] ?? '');
    $present = trim($_POST['present_address'] ?? '');
    $sss = trim($_POST['sss_no'] ?? '');
    $phil = trim($_POST['philhealth_no'] ?? '');
    $pagibig = trim($_POST['pagibig_no'] ?? '');
    $tin = trim($_POST['tin_no'] ?? '');
    $atm = trim($_POST['atm_no'] ?? '');
    $portalInput = trim($_POST['portal_password'] ?? '');
    $portal = '';
    if ($portalInput !== '') {
        $portal = password_hash($portalInput, PASSWORD_DEFAULT);
    }
    $department = trim($_POST['department'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $status = $_POST['employment_status'] ?? 'Regular';
    $salary = is_superadmin() ? (float)($_POST['basic_salary'] ?? 0) : 0.0;
    if (!is_superadmin() && $id > 0) {
        $currentSalary = $conn->prepare('SELECT basic_salary FROM employees WHERE employee_id=? LIMIT 1');
        $currentSalary->bind_param('i', $id);
        $currentSalary->execute();
        $salary = (float)($currentSalary->get_result()->fetch_assoc()['basic_salary'] ?? 0);
    }
    $dateHired = trim($_POST['date_hired'] ?? '') ?: null;
    $emergencyName = trim($_POST['emergency_contact_name'] ?? '');
    $emergencyPhone = trim($_POST['emergency_contact_phone'] ?? '');
    $emergencyAddress = trim($_POST['emergency_contact_address'] ?? '');
    $dependentName = trim($_POST['dependent_name'] ?? '');
    $dependentRelationship = trim($_POST['dependent_relationship'] ?? '');
    $dependentBirth = trim($_POST['dependent_birth_date'] ?? '') ?: null;
    $education = trim($_POST['education_background'] ?? '');
    $reference = trim($_POST['character_reference'] ?? '');

    if ($first === '' || $last === '') {
        $error = 'First name and last name are required.';
    } elseif ($salary < 0) {
        $error = 'Salary cannot be negative.';
    } elseif (!in_array($status, ['Regular', 'Probationary', 'Contractual', 'Part-Time'], true)) {
        $error = 'Please choose a valid employment status.';
    }

    $currentPhoto = null;
    if ($id > 0) {
        $stmt = $conn->prepare('SELECT photo_path FROM employees WHERE employee_id=? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $currentPhoto = $stmt->get_result()->fetch_assoc()['photo_path'] ?? null;
    }

    if ($id > 0 && $portalInput === '') {
        $existingPortal = $conn->prepare('SELECT portal_password FROM employees WHERE employee_id=? LIMIT 1');
        $existingPortal->bind_param('i', $id);
        $existingPortal->execute();
        $portal = $existingPortal->get_result()->fetch_assoc()['portal_password'] ?? null;
    }

    if ($error === '') {
        $photo = upload_employee_photo($_FILES['employee_photo'] ?? null, $currentPhoto);
        if (is_array($photo)) {
            $error = $photo['error'];
        } else {
            $photoPath = $photo;
        }
    }

    if ($error === '') {
        if ($id > 0) {
            $sql = 'UPDATE employees SET portal_password=?,photo_path=?,first_name=?,middle_name=?,last_name=?,gender=?,birth_date=?,email=?,contact_number=?,civil_status=?,nationality=?,religion=?,permanent_address=?,present_address=?,sss_no=?,philhealth_no=?,pagibig_no=?,tin_no=?,atm_no=?,department=?,position=?,employment_status=?,basic_salary=?,date_hired=?,emergency_contact_name=?,emergency_contact_phone=?,emergency_contact_address=?,dependent_name=?,dependent_relationship=?,dependent_birth_date=?,education_background=?,character_reference=? WHERE employee_id=?';
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('sssssssssssssssssssssssdssssssssi', $portal, $photoPath, $first, $middle, $last, $gender, $birth, $email, $contact, $civil, $nationality, $religion, $permanent, $present, $sss, $phil, $pagibig, $tin, $atm, $department, $position, $status, $salary, $dateHired, $emergencyName, $emergencyPhone, $emergencyAddress, $dependentName, $dependentRelationship, $dependentBirth, $education, $reference, $id);
        } else {
            $employeeNo = next_employee_no($conn);
            $sql = 'INSERT INTO employees(employee_no,portal_password,photo_path,first_name,middle_name,last_name,gender,birth_date,email,contact_number,civil_status,nationality,religion,permanent_address,present_address,sss_no,philhealth_no,pagibig_no,tin_no,atm_no,department,position,employment_status,basic_salary,date_hired,emergency_contact_name,emergency_contact_phone,emergency_contact_address,dependent_name,dependent_relationship,dependent_birth_date,education_background,character_reference) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('sssssssssssssssssssssssdsssssssss', $employeeNo, $portal, $photoPath, $first, $middle, $last, $gender, $birth, $email, $contact, $civil, $nationality, $religion, $permanent, $present, $sss, $phil, $pagibig, $tin, $atm, $department, $position, $status, $salary, $dateHired, $emergencyName, $emergencyPhone, $emergencyAddress, $dependentName, $dependentRelationship, $dependentBirth, $education, $reference);
        }

        if ($stmt->execute()) {
            $savedId = $id > 0 ? $id : $conn->insert_id;
            header('Location: personalinfo.php?edit=' . $savedId . '&saved=1');
            exit;
        }

        $error = 'Unable to save the employee. Please check the information and try again.';
    }
}

if ($editId > 0) {
    $stmt = $conn->prepare('SELECT * FROM employees WHERE employee_id=? LIMIT 1');
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $employee = $stmt->get_result()->fetch_assoc();
}

if (isset($_GET['saved'])) {
    $message = 'Employee information saved successfully.';
}

function field_value(?array $employee, string $key): string
{
    return e($employee[$key] ?? '');
}

$disabled = 'disabled';
$isNew = !$employee;

$departments = [];
$departmentResult = $conn->query("SELECT department_name FROM departments WHERE status='Active' ORDER BY department_name ASC");
if ($departmentResult) {
    while ($row = $departmentResult->fetch_assoc()) {
        $departments[] = $row['department_name'];
    }
}

if (!$departments) {
    $departments = [
        'Operations',
        'Finance & Accounting',
        'Human Resources',
        'Information Technology',
        'Sales & Marketing',
        'Customer Support'
    ];
}

$positions = [];
$positionResult = $conn->query("SELECT d.department_name, p.position_name FROM positions p INNER JOIN departments d ON d.department_id=p.department_id WHERE d.status='Active' ORDER BY d.department_name ASC, p.position_name ASC");
if ($positionResult) {
    while ($row = $positionResult->fetch_assoc()) {
        $positions[$row['department_name']][] = $row['position_name'];
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<!-- FAVICON: Put your favicon file at /assets/favicon.png -->
<link rel="icon" type="image/png" href="../assets/favicon.png">
    <title>LCC Payroll System</title>
    <link rel="stylesheet" href="../assets/css/app.css?v=20261012-nav1">
    <link rel="stylesheet" href="../assets/css/apple-system.css?v=20260916-apple9">
    <script src="../assets/js/app.js?v=20261012-nav1" defer></script>
    <script src="../assets/js/apple-motion.js?v=20261012-nav1" defer></script>
</head>

<body>
    <div class="app">
        <?php sidebar($employee ? 'employees' : 'add'); ?>
        <main class="main">
            <?php topbar($employee ? 'Employee Information' : 'Add Employee'); ?>
            <div class="content">
                <div class="page-heading">
                    <div>
                        <div class="eyebrow">Employee Management</div>
                        <h1><?php echo $employee ? 'Employee Information' : 'Add a New Employee'; ?></h1>
                        <p><?php echo $employee ? 'Review the information below. Click Edit Employee before changing anything.' : 'Click Add Employee when you are ready to enter the employee information.'; ?></p>
                        <div class="employee-id-summary"><span>Employee ID</span><strong><?php echo $employee ? e($employee['employee_no']) : 'Auto-generated'; ?></strong></div>
                    </div>
                    <div class="print-tools">
                        <a class="btn btn-secondary" href="personalinfo.php?cancel=1">Cancel</a>           

                        <a class="btn btn-secondary" href="employees.php">← Employee List</a>                                                                      
                        <?php if ($employee): ?><button type="button" class="btn btn-secondary" onclick="printEmployeeInformation(<?php echo (int)$employee['employee_id']; ?>)">🖨 Print Employee</button><?php endif; ?>
                        <?php if ($isNew): ?><button class="btn btn-primary" type="button" onclick="enablePersonForm()">＋ Add Employee</button><?php else: ?><button class="btn btn-primary" type="button" onclick="enablePersonForm()">✎ Edit Employee</button><?php endif; ?>
                    </div>
                </div>

                <?php if ($message): ?><div class="notice ok"><?php echo e($message); ?></div><?php endif; ?>
                <?php if ($error): ?><div class="notice err"><?php echo e($error); ?></div><?php endif; ?>

                <div id="lockedNotice" class="locked-box">
                    <?php echo $employee ? '🔒 This employee is in view mode. Click Edit Employee to make the fields editable.' : '🔒 The form is locked. Click Add Employee to start entering employee information.'; ?>
                </div>

                <form id="employeeForm" class="form-card" method="post" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="employee_id" value="<?php echo (int)($employee['employee_id'] ?? 0); ?>">
                    <div class="form-body">
                        <div class="section-title">Employee ID</div>
                        <div class="form-grid">
                            <div class="field"><label>Employee ID</label><input type="text" value="<?php echo $employee ? field_value($employee, 'employee_no') : e(next_employee_no($conn)) ?>" placeholder="25-0001" readonly></div>
                        </div>

                        <div class="section-title">Personal Information</div>
                        <div class="form-grid">
                            <div class="field"><label>First Name <span class="required">*</span></label><input <?php echo $disabled; ?> name="first_name" value="<?php echo field_value($employee, 'first_name'); ?>" required></div>
                            <div class="field"><label>Middle Name</label><input <?php echo $disabled; ?> name="middle_name" value="<?php echo field_value($employee, 'middle_name'); ?>"></div>
                            <div class="field"><label>Last Name <span class="required">*</span></label><input <?php echo $disabled; ?> name="last_name" value="<?php echo field_value($employee, 'last_name'); ?>" required></div>
                            <div class="field"><label>Gender</label><select <?php echo $disabled; ?> name="gender">
                                    <option value="Male" <?php echo ($employee['gender'] ?? '') === 'Male' ? 'selected' : ''; ?>>Male</option>
                                    <option value="Female" <?php echo ($employee['gender'] ?? '') === 'Female' ? 'selected' : ''; ?>>Female</option>
                                    <option value="Other" <?php echo ($employee['gender'] ?? 'Other') === 'Other' ? 'selected' : ''; ?>>Other</option>
                                </select></div>
                            <div class="field"><label>Birth Date</label><input <?php echo $disabled; ?> type="date" name="birth_date" value="<?php echo field_value($employee, 'birth_date'); ?>"></div>
                            <div class="field"><label>Civil Status</label><input <?php echo $disabled; ?> name="civil_status" value="<?php echo field_value($employee, 'civil_status'); ?>"></div>
                            <div class="field"><label>Nationality</label><input <?php echo $disabled; ?> name="nationality" value="<?php echo field_value($employee, 'nationality'); ?>"></div>
                            <div class="field"><label>Religion</label><input <?php echo $disabled; ?> name="religion" value="<?php echo field_value($employee, 'religion'); ?>"></div>
                        </div>

                        <div class="section-title">Contact Information</div>
                        <div class="form-grid">
                            <div class="field"><label>Contact Number</label><input <?php echo $disabled; ?> name="contact_number" value="<?php echo field_value($employee, 'contact_number'); ?>"></div>
                            <div class="field"><label>Email Address</label><input <?php echo $disabled; ?> type="email" name="email" value="<?php echo field_value($employee, 'email'); ?>"></div>
                            <div class="field"><label>Portal Password</label><input <?php echo $disabled; ?> type="password" name="portal_password" value="" placeholder="Set or change portal password"><small>Employees use this password with their Employee ID to access the Employee Portal.</small></div>
                            <div class="field full"><label>Permanent Address</label><textarea <?php echo $disabled; ?> name="permanent_address"><?php echo field_value($employee, 'permanent_address'); ?></textarea></div>
                            <div class="field full"><label>Present Address</label><textarea <?php echo $disabled; ?> name="present_address"><?php echo field_value($employee, 'present_address'); ?></textarea></div>
                        </div>

                        <div class="section-title">Government IDs</div>
                        <div class="form-grid">
                            <div class="field"><label>SSS No.</label><input <?php echo $disabled; ?> name="sss_no" value="<?php echo field_value($employee, 'sss_no'); ?>"></div>
                            <div class="field"><label>PhilHealth No.</label><input <?php echo $disabled; ?> name="philhealth_no" value="<?php echo field_value($employee, 'philhealth_no'); ?>"></div>
                            <div class="field"><label>Pag-IBIG No.</label><input <?php echo $disabled; ?> name="pagibig_no" value="<?php echo field_value($employee, 'pagibig_no'); ?>"></div>
                            <div class="field"><label>TIN No.</label><input <?php echo $disabled; ?> name="tin_no" value="<?php echo field_value($employee, 'tin_no'); ?>"></div>
                            <div class="field"><label>ATM No.</label><input <?php echo $disabled; ?> name="atm_no" value="<?php echo field_value($employee, 'atm_no'); ?>"></div>
                        </div>

                        <div class="section-title">Employment</div>
                        <div class="form-grid">
                            <div class="field"><label>Department</label><select <?php echo $disabled; ?> name="department">
                                    <option value="">Select department</option><?php foreach ($departments as $d): ?><option value="<?php echo e($d); ?>" <?php echo ($employee['department'] ?? '') === $d ? 'selected' : ''; ?>><?php echo e($d); ?></option><?php endforeach; ?>
                                </select></div>
                            <!-- <div class="field"><label>Position</label><input <?php echo $disabled; ?> name="position" value="<?php echo field_value($employee, 'position'); ?>"></div> -->
                            <div class="field">
                                <label>Position</label>

                                <select <?php echo $disabled; ?> name="position" id="position">
                                    <option value="">Select position</option>
                                </select>
                            </div>
                            <div class="field"><label>Employment Status</label><select <?php echo $disabled; ?> name="employment_status"><?php foreach (['Regular', 'Probationary', 'Contractual', 'Part-Time'] as $status): ?><option value="<?php echo $status; ?>" <?php echo ($employee['employment_status'] ?? 'Regular') === $status ? 'selected' : ''; ?>><?php echo $status; ?></option><?php endforeach; ?></select></div>
                            <?php if (is_superadmin()): ?><div class="field"><label>Basic Salary</label><input <?php echo $disabled; ?> type="number" min="0" step="0.01" name="basic_salary" value="<?php echo field_value($employee, 'basic_salary'); ?>"></div><?php endif; ?>
                            <div class="field"><label>Date Hired</label><input <?php echo $disabled; ?> type="date" name="date_hired" value="<?php echo field_value($employee, 'date_hired'); ?>"></div>
                        </div>

                        <div class="section-title">Emergency Contact</div>
                        <div class="form-grid">
                            <div class="field"><label>Full Name</label><input <?php echo $disabled; ?> name="emergency_contact_name" value="<?php echo field_value($employee, 'emergency_contact_name'); ?>"></div>
                            <div class="field"><label>Phone Number</label><input <?php echo $disabled; ?> name="emergency_contact_phone" value="<?php echo field_value($employee, 'emergency_contact_phone'); ?>"></div>
                            <div class="field full"><label>Address</label><textarea <?php echo $disabled; ?> name="emergency_contact_address"><?php echo field_value($employee, 'emergency_contact_address'); ?></textarea></div>
                        </div>

                        <div class="section-title">Dependent</div>
                        <div class="form-grid">
                            <div class="field"><label>Full Name</label><input <?php echo $disabled; ?> name="dependent_name" value="<?php echo field_value($employee, 'dependent_name'); ?>"></div>
                            <div class="field"><label>Relationship</label><input <?php echo $disabled; ?> name="dependent_relationship" value="<?php echo field_value($employee, 'dependent_relationship'); ?>"></div>
                            <div class="field"><label>Birth Date</label><input <?php echo $disabled; ?> type="date" name="dependent_birth_date" value="<?php echo field_value($employee, 'dependent_birth_date'); ?>"></div>
                        </div>

                        <div class="section-title">Education and Character Reference</div>
                        <div class="form-grid">
                            <div class="field full"><label>Educational Background</label><textarea <?php echo $disabled; ?> name="education_background"><?php echo field_value($employee, 'education_background'); ?></textarea></div>
                            <div class="field full"><label>Character Reference</label><textarea <?php echo $disabled; ?> name="character_reference"><?php echo field_value($employee, 'character_reference'); ?></textarea></div>
                        </div>

                        <div class="section-title">Employee Photo</div>
                        <div class="form-grid">
                            <div class="field full"><label>Photo <span class="mini">JPG or PNG, maximum 2MB</span></label><input <?php echo $disabled; ?> type="file" name="employee_photo" accept=".jpg,.jpeg,.png,image/jpeg,image/png"></div>
                        </div>
                        <?php if ($employee && !empty($employee['photo_path'])): ?><div style="margin-top:12px"><img class="profile-photo" src="../employee_photo.php?id=<?php echo (int)$employee['employee_id']; ?>" alt="Employee photo"></div><?php endif; ?>
                    </div>
                    <div class="form-actions">
                        <button id="saveButton" class="btn btn-primary" type="submit" disabled>✓ Save Employee</button>
                        <a class="btn btn-secondary" href="personalinfo.php?cancel=1">Cancel</a>
                    </div>
                </form>
            </div>
        </main>
    </div>
    <script>
        function enablePersonForm() {
            document.querySelectorAll('#employeeForm input:not([name="employee_id"]), #employeeForm select, #employeeForm textarea').forEach(function(field) {
                if (!field.hasAttribute('data-fixed')) field.disabled = false;
            });
            document.getElementById('saveButton').disabled = false;
            document.getElementById('lockedNotice').textContent = '✎ Editing is enabled. Review the information, then click Save Employee when finished.';
        }
        const positions = <?php echo json_encode($positions, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;

        const departmentSelect = document.querySelector('select[name="department"]');
        const positionSelect = document.getElementById('position');

        const currentPosition = <?php echo json_encode($employee['position'] ?? '', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;

        function loadPositions() {

            const selectedDepartment = departmentSelect.value;

            positionSelect.innerHTML = '<option value="">Select position</option>';

            if (positions[selectedDepartment]) {

                positions[selectedDepartment].forEach(function(position) {

                    const option = document.createElement('option');

                    option.value = position;
                    option.textContent = position;

                    if (position === currentPosition) {
                        option.selected = true;
                    }

                    positionSelect.appendChild(option);
                });
            }
        }

        departmentSelect.addEventListener('change', function() {

            positionSelect.innerHTML = '<option value="">Select position</option>';

            const selectedDepartment = departmentSelect.value;

            if (positions[selectedDepartment]) {

                positions[selectedDepartment].forEach(function(position) {

                    const option = document.createElement('option');

                    option.value = position;
                    option.textContent = position;

                    positionSelect.appendChild(option);
                });
            }
        });

        loadPositions();
    </script>
<div id="nativeEmployeePrintSheet" class="native-print-sheet" aria-hidden="true"></div>
<script>
async function printEmployeeInformation(id){
    const sheet=document.getElementById('nativeEmployeePrintSheet');
    sheet.innerHTML='<div class="print-loading">Preparing employee record…</div>';
    sheet.classList.add('active');
    try{
        const response=await fetch('employee_preview.php?id='+encodeURIComponent(id),{headers:{'X-Requested-With':'XMLHttpRequest'}});
        if(!response.ok) throw new Error('Request failed');
        sheet.innerHTML=await response.text();
        window.print();
    }catch(error){
        sheet.innerHTML='<div class="print-error">Unable to prepare the employee record.</div>';
        window.print();
    }
    window.addEventListener('afterprint',function(){sheet.classList.remove('active');sheet.innerHTML='';},{once:true});
}
</script>

</body>

</html>
