<?php
session_start();
if(!isset($_SESSION['student_id'])){
    header("Location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile — CCS Sit-in Monitoring</title>
    <link rel="stylesheet" href="userdb.css">
    <link rel="icon" type="image/png" href="pictures/uclogo.png">
    <style>
        .edit-page-wrapper {
            min-height: calc(100vh - 66px);
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 36px 20px 60px;
            background: var(--off-white);
        }
        .edit-card {
            background: var(--white);
            width: 100%;
            max-width: 520px;
            border-radius: var(--radius);
            box-shadow: var(--card-shadow);
            overflow: hidden;
        }
        .edit-card-header {
            background: linear-gradient(135deg, var(--navy) 0%, var(--navy-mid) 100%);
            padding: 20px 28px;
            color: var(--white);
            font-size: 16px;
            font-weight: 600;
        }
        .edit-card-body { padding: 30px 28px; }

        .profile-pic-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 28px;
        }
        .profile-pic-preview {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--blue);
            box-shadow: 0 0 0 5px rgba(30,111,224,0.1);
            margin-bottom: 12px;
            transition: transform var(--transition);
        }
        .profile-pic-preview:hover { transform: scale(1.04); }
        .change-photo-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--off-white);
            border: 1.5px solid var(--gray-100);
            color: var(--blue);
            font-size: 13px;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            padding: 7px 16px;
            border-radius: 100px;
            cursor: pointer;
            transition: all var(--transition);
        }
        .change-photo-btn:hover {
            background: rgba(30,111,224,0.08);
            border-color: var(--blue);
        }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

        .edit-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 6px;
        }
        .edit-input {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid var(--gray-100);
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-family: 'Outfit', sans-serif;
            color: var(--navy);
            background: #fafbff;
            transition: all var(--transition);
            outline: none;
            margin-bottom: 16px;
        }
        .edit-input:focus {
            border-color: var(--blue);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(30,111,224,0.1);
        }
        .edit-input[readonly] {
            background: var(--gray-100);
            color: var(--gray-500);
            cursor: not-allowed;
        }
        .save-btn {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, var(--blue) 0%, var(--blue-light) 100%);
            color: var(--white);
            border: none;
            border-radius: var(--radius-sm);
            font-size: 15px;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            cursor: pointer;
            transition: all var(--transition);
            margin-top: 4px;
        }
        .save-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(30,111,224,0.35);
        }
        .back-link {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin-top: 16px;
            font-size: 13px;
            color: var(--gray-500);
            text-decoration: none;
            transition: color var(--transition);
        }
        .back-link:hover { color: var(--blue); }
    </style>
</head>
<body>

<nav class="dashboard-navbar">
    <div class="dashboard-left">Dashboard</div>
    <ul class="dashboard-right">
    <li><a href="notifications.php">Notification</a></li>
    <li><a href="userdb.php">Home</a></li>
    <li><a href="edit_profile.php" class="active">Edit Profile</a></li>
    <li><a href="history.php">History</a></li>
    <li><a href="student_reservation.php">Reservation</a></li>
    <li><a href="logout.php" class="logout-btn">Log Out</a></li>
</ul>
</nav>

<div class="edit-page-wrapper">
    <div class="edit-card">
        <div class="edit-card-header">Edit Profile</div>
        <div class="edit-card-body">
            <form action="Database/update_profile.php" method="POST" enctype="multipart/form-data">

                <div class="profile-pic-section">
                    <img src="profile_pictures/<?php echo $_SESSION['profile_picture'] ?? 'default.png'; ?>"
                         alt="Profile Picture"
                         class="profile-pic-preview"
                         id="preview">
                    <label for="profile_picture" class="change-photo-btn">
                        📷 Change Photo
                    </label>
                    <input type="file" name="profile_picture" id="profile_picture" accept="image/*" style="display:none;" onchange="previewImage(this)">
                </div>

                <label class="edit-label">ID Number</label>
                <input class="edit-input" type="text" name="id_number" value="<?php echo htmlspecialchars($_SESSION['id_number']); ?>" readonly>

                <div class="form-row">
                    <div>
                        <label class="edit-label">Last Name</label>
                        <input class="edit-input" type="text" name="last_name" value="<?php echo htmlspecialchars($_SESSION['last_name']); ?>" required>
                    </div>
                    <div>
                        <label class="edit-label">First Name</label>
                        <input class="edit-input" type="text" name="first_name" value="<?php echo htmlspecialchars($_SESSION['first_name']); ?>" required>
                    </div>
                </div>

                <label class="edit-label">Middle Name</label>
                <input class="edit-input" type="text" name="middle_name" value="<?php echo htmlspecialchars($_SESSION['middle_name']); ?>">

                <label class="edit-label">Course</label>
                <input class="edit-input" type="text" name="course" value="<?php echo htmlspecialchars($_SESSION['course']); ?>" required>

                <label class="edit-label">Year Level</label>
                <input class="edit-input" type="text" name="year_level" value="<?php echo htmlspecialchars($_SESSION['year_level']); ?>" required>

                <label class="edit-label">Email Address</label>
                <input class="edit-input" type="email" name="email" value="<?php echo htmlspecialchars($_SESSION['email']); ?>" required>

                <label class="edit-label">Address</label>
                <textarea name="address" class="edit-input" rows="3" required><?php echo htmlspecialchars($_SESSION['address']); ?></textarea>

                <button type="submit" class="save-btn">Save Changes</button>
            </form>

            <a href="userdb.php" class="back-link">← Back to Dashboard</a>
        </div>
    </div>
</div>

<script>
function previewImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => document.getElementById('preview').src = e.target.result;
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

</body>
</html>
