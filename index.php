<?php
ob_start();
session_start();

// Include database connection
$host = 'localhost';
$db = 'datablitz';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

try {
    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Handle logout
if (isset($_GET['logout']) && $_GET['logout'] === 'true') {
    session_start();
    session_unset(); // Clear all session variables
    session_destroy(); // Destroy the session

    // Redirect to a login page or homepage
    header("Location: index.php?page=login");
    exit;
}


// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = isset($_POST['email']) ? $_POST['email'] : null;
    $password = isset($_POST['password']) ? $_POST['password'] : null;

    // Check if the user is an admin
    $stmt = $pdo->prepare("SELECT * FROM admin WHERE admin_Email = :email AND admin_Password = :password");
    $stmt->execute(['email' => $email, 'password' => $password]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($admin) {
        $_SESSION['user_role'] = 'admin';
        $_SESSION['user_name'] = $admin['admin_FName'] . ' ' . $admin['admin_LName'];
        $_SESSION['user_Id'] = $admin['admin_ID']; // Set user session ID for admin
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // Check if the user is a regular user
    $stmt = $pdo->prepare("SELECT * FROM user WHERE user_Email = :email AND user_Password = :password");
    $stmt->execute(['email' => $email, 'password' => $password]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $_SESSION['user_role'] = 'user';
        $_SESSION['user_name'] = $user['user_Fname'] . ' ' . $user['user_Lname'];
        $_SESSION['user_Id'] = 1; // Set user session ID for regular user
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    $error_message = "Invalid email or password.";
}



// Handle Search Request
if (isset($_GET['search_query']) && !empty($_GET['search_query'])) {
    $searchQuery = $_GET['search_query'];
    $stmt = $pdo->prepare("SELECT 
                                p.*, 
                                COALESCE(AVG(r.rev_Rating), 0) AS avg_rating
                            FROM 
                                product p
                            LEFT JOIN 
                                reviews r 
                            ON 
                                p.prod_Id = r.prod_Id
                            WHERE 
                                p.prod_Id LIKE :search OR 
                                p.prod_Name LIKE :search OR 
                                p.prod_Desc LIKE :search
                            GROUP BY 
                                p.prod_Id");
    $stmt->execute(['search' => '%' . $searchQuery . '%']);
} else {
    // Default to fetching all products if no search query is provided
    $stmt = $pdo->query("
        SELECT 
            p.*, 
            COALESCE(AVG(r.rev_Rating), 0) AS avg_rating
        FROM 
            product p
        LEFT JOIN 
            reviews r 
        ON 
            p.prod_Id = r.prod_Id
        GROUP BY 
            p.prod_Id
    ");
}

// Handle Deletion Request
if (isset($_POST['delete_prod_Id'])) {
    $prod_Id = $_POST['delete_prod_Id'];

    // Prepare and execute the delete query
    $stmt = $pdo->prepare("DELETE FROM product WHERE prod_Id = :prod_Id");
    $stmt->bindParam(':prod_Id', $prod_Id, PDO::PARAM_INT);

    if ($stmt->execute()) {
        // Return a success message and indicate that the page should reload
        echo json_encode(['status' => 'success', 'message' => 'Product deleted successfully!', 'reload' => true]);
    } else {
        // Return a failure message
        echo json_encode(['status' => 'error', 'message' => 'Failed to delete product. Please try again.']);
    }
    exit(); // Ensure no further output is sent
}


// Display content based on user role
if (isset($_SESSION['user_role'])) {
    $page = isset($_GET['page']) ? $_GET['page'] : 'orders'; // Default to 'orders'

    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Datablitz</title>
        
        <style>
            body {
                margin: 0;
                font-family: Roboto, sans-serif;
                display: flex;
            }
            .sidebar {
                background-color: #003366;
                color: white;
                width: 250px;
                height: 100vh;
                padding: 20px;
                padding-top: 70px;  /* Adjust to give space for the top bar */
                position: fixed;
                top: 0;
                left: 0;
                z-index: 1100;
            }
            .sidebar h2 {
                color: white;
                font-size: 1.5em;
                margin-bottom: 20px;
            }
            .sidebar ul {
                list-style: none;
                padding: 0;
            }
            .sidebar ul li {
                margin: 15px 0;
            }
            .sidebar ul li a {
                color: white;
                text-decoration: none;
                font-size: 1.2em;
            }
            .sidebar ul li a:hover {
                color: #00ccff;
            }
            .content {
                margin-left: 50px;
                padding: 20px;
                width: 100%;
            }
            .stats {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 20px;
                margin-bottom: 20px;
            }
            .stats .box {
                background-color: #ffffff;
                padding: 20px;
                border-radius: 8px;
                text-align: left;
                border: 2px solid #E2E1E1;
            }
            .stats .box h3 {
                margin: 0;
                font-size: 1.5em;
                color: #333;
                font-family: Roboto;
            }
            .stats .box p {
                margin: 10px 0 0;
                font-size: 3.0em;
                color: #000000;
                font-style: bold;
                font-family: Roboto;
            }
            /* Table Styles */
            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 20px;
                border: 2px solid #003366;
                border-radius: 8px;
                overflow: hidden;
            }

            th, td {
                padding: 12px 15px;
                text-align: left;
                border-bottom: 2px solid #ddd;
            }
            th {
                background-color: #003366;
                color: white;
            }
            tr:last-child td {
                border-bottom: none;
            }
            tr:hover {
                background-color: #f2f2f2;
            }
            #table-container {
                border: 2px solid #E2E1E1;
                border-radius: 8px;
                padding: 20px;
                margin-top: 20px;
                position: relative;
            }

            .filter-container {
                margin-bottom: 10px;
                position: relative; 
            }
            #category-filter, #stock-status-filter, #sortOrder, #statusFilter {
                padding: 5px;
                font-size: 1em;
            }
                
            .content {
                margin-left: 300px;  
                margin-top: 50px;     
                padding: 20px;
                width: 100%;
            }

            .top-bar {
                background-color: #ffffff;
                color: black;
                display: flex;
                justify-content: center; 
                align-items: center;
                padding: 10px 20px;
                position: fixed;
                top: 0;
                width: 100%; 
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
                z-index: 1000;
            }

            .top-bar .search-container {
                display: flex;
                align-items: center;
                margin-left: 20px;
            }

            .top-bar .search-container input[type="text"] {
                width: 300px;
                padding: 8px;
                border: 1px solid #ccc;
                border-radius: 4px;
            }

            .top-bar .search-container button {
                padding: 8px 15px;
                margin-left: 10px;
                background-color: #003366;
                color: white;
                border: none;
                border-radius: 4px;
                cursor: pointer;
            }

            .top-bar .search-container button:hover {
                background-color: #0099cc;
            }

            .stock-status {
                display: flex;
                align-items: center;
                gap: 5px;
            }

            .circle {
                width: 10px;
                height: 10px;
                border-radius: 50%;
                display: inline-block;
            }

            button, .edit-btn{
                background: none; 
                border: none; 
                cursor: pointer; 
                padding: 5px;
                cursor: pointer; 
            }

            button img, .edit-btn img {
                width: 15px; 
                height: 15px; 
            }

            button:hover {
                opacity: 0.5;
            }

            button:not(:last-child) {
                margin-right: 10px;
            }

            #add-product-btn {
                position: absolute;
                top: 0;
                right: 0;
                background-color: #003366;
                color: white;
                border: none;
                border-radius: 4px;
                font-size: 18px;
                cursor: pointer;
                padding: 10px 20px;
            }
            </style>
        </head>
    <body>';


    
    
    //ADMIN PAGE 

    if ($_SESSION['user_role'] === 'admin') {

        echo    '<div class="top-bar">';
        echo    '<div class="search-container">';
        echo    '<form method="GET" action="' . htmlspecialchars($_SERVER['PHP_SELF']) . '">';
        echo    '<input type="hidden" name="page" value="orders">';
        echo    '<input type="text" name="search_query" id="search-bar" placeholder="Search..." value="' . (isset($_GET['search_query']) ? htmlspecialchars($_GET['search_query']) : '') . '">';
        echo    '<button type="submit">Search</button>';
        echo    '</form>';
        echo    '</div>';
        echo    '</div>';
    
        echo '<div class="sidebar">';
        echo '<img src="logo.png" alt="Logo" style="width: 90%; height: auto; margin-bottom: 20px;">';
        echo '<ul>';
        echo '<li><a href="?page=orders">Orders</a></li>';
        echo '<li><a href="?page=inventory">Inventory</a></li>';
        echo '<li><a href="' . $_SERVER['PHP_SELF'] . '?logout=true">Logout</a></li>';
        echo '</ul>';
        echo '</div>';
    
        echo '<div class="content">';

        if ($page === 'orders') {

   

            echo '<section id="orders">';
            echo '<style> 
               .stats {
                    display: grid;
                    grid-template-columns: repeat(3, 1fr);
                    gap: 20px;
                    margin-bottom: 20px;
                    
                }

                .stats .box {
                    background-color: #ffffff;
                    padding: 20px;
                    border-radius: 8px;
                    text-align: left;
                    border: 2px solid #E2E1E1;
                    font-family: Roboto, sans-serif;
                }

                .stats .box h3 {
                    margin: 0;
                    font-size: 1.5em;
                    color: #333;
                    font-family: Roboto;
                }

                .stats .box .order-status {
                    display: flex; 
                    gap: 60px; 
                    align-items: center; 
                }

                .stats .box .status-item {
                    text-align: left;
                    color: #000000;
                    font-size: 1.2em;
                }

                .stats .box .status-item p {
                    text-align: left;
                    color: #000000;
                    font-size: 1.2em;
                    margin-top: 20px;
                }

                .stats .box .status-item h4 {
                    font-size: 0.9em; 
                    color: #000000;
                    margin-top: 10px;
                }
     
                .table-box {
                    background-color: #ffffff;
                    padding: 20px;
                    border-radius: 8px;
                    border: 2px solid #E2E1E1;
                    margin-top: 20px;
                    font-family: Roboto, sans-serif;
                    display: absolute;
                    justify-content: space-between;
                    align-items: flex-start;
                    gap: 20px;
                }

                .table-box h3 {
                    margin: 0 0 20px 0;
                    font-size: 1.5em;
                    color: #333;
                }

                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 20px;
                    border: 2px solid #003366;
                    border-radius: 8px;
                    overflow: hidden;
                }

                table th, table td {
                    padding: 12px 15px;
                    text-align: left;
                    border-bottom: 2px solid #ddd;
                }

                table th {
                    background-color: #003366;
                    color: white;
                }

                .action-buttons a {
                    padding: 5px 10px;
                    background-color: #2980b9;
                    color: white;
                    text-decoration: none;
                    border-radius: 4px;
                }

                .stat-item {
                    display: inline-block;
                    padding: 5px 15px;
                    border-radius: 20px;
                    color: white;
                    font-size: 0.9em;
                    text-align: center;
                    color: black;
                    border: 1px solid black; 
                    font-weight: bold;
                    text-transform: lowercase;
                }
                    
                .stat-item.pending {
                    background-color: #FFA500; /* Orange for Pending */
                }

                .stat-item.shipped {
                    background-color: #3CD040; /* Green for Shipped */
                }

                .stat-item.cancelled {
                    background-color: #FF2205; /* Red for Cancelled */
                }

                .stat-item.delivered {
                    background-color: #4A90E2; /* Blue for Delivered */
                }

                .order-status-checker {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    margin-top: 50px;
                    gap: 20px;
                    width: 600px;
                }

                .order-status-checker input,
                .order-status-checker .output-placeholder,
                .order-status-checker button {
                    margin-right: 15px; 
                    flex: 1;
                }


                .order-status-checker input,
                .order-status-checker .output-box {
                    flex: 1;
                    padding: 8px;
                    font-size: 1em;
                    border: 1px solid #ccc;
                    border-radius: 4px;
                    background-color: #fff;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    color: #333;
                    text-align: left;
                    height: 20px; 
                }

                .order-status-checker button {
                    background-color: #003366;
                    color: #fff;
                    padding: 8px 16px;
                    font-size: 1em;
                    border: none;
                    border-radius: 4px;
                    cursor: pointer;
                }

                .order-status-checker button:hover {
                    background-color: #002244;
                }

                .order-status-checker .output-box .stat-item {
                    padding: 5px 10px;
                    border-radius: 20px;
                    font-weight: bold;
                    color: black;
                }

                .stat-item.pending {
                    background-color: #FFA500; /* Orange for Pending */
                }

                .stat-item.shipped {
                    background-color: #3CD040; /* Green for Shipped */
                }

                .stat-item.cancelled {
                    background-color: #FF2205; /* Red for Cancelled */
                }

                .stat-item.delivered {
                    background-color: #4A90E2; /* Blue for Delivered */
                }


            </style>';
    
        // Fetch total orders, total revenue, and order status statistics
        $stmt = $pdo->prepare("SELECT COUNT(*) AS total_orders FROM orders");
        $stmt->execute();
        $totalOrders = $stmt->fetch(PDO::FETCH_ASSOC)['total_orders'];

        $stmt = $pdo->prepare("SELECT SUM(order_Total) AS total_revenue FROM orders");
        $stmt->execute();
        $totalRevenue = $stmt->fetch(PDO::FETCH_ASSOC)['total_revenue'];

        $stmt = $pdo->prepare("SELECT order_Status, COUNT(*) AS status_count FROM orders GROUP BY order_Status");
        $stmt->execute();
        $statusCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Map the status counts to a more easily accessible array
        $statusMap = [
            'Pending' => 0,
            'Shipped' => 0,
            'Delivered' => 0,
            'Cancelled' => 0,
        ];

        // Populate the status map with the fetched counts
        foreach ($statusCounts as $status) {
            if (array_key_exists($status['order_Status'], $statusMap)) {
                $statusMap[$status['order_Status']] = $status['status_count'];
            }
        }

        // Statistics Box
        echo '<div class="stats">';
        echo '<div class="box">';
        echo '<h3>Total Orders</h3>';
        echo '<p>' . $totalOrders . '</p>';
        echo '</div>';

        echo '<div class="box">';
        echo '<h3>Total Revenue</h3>';
        echo '<p>₱' . number_format($totalRevenue, 2) . '</p>';
        echo '</div>';

        echo '<div class="box">';
        echo '  <h3>Order Status</h3>';
        echo '  <div class="order-status">';
        echo '      <div class="status-item">';
        echo '          <p>' . $statusMap['Pending'] . '</p>';
        echo '          <h4>Pending</h4>';
        echo '      </div>';
        echo '      <div class="status-item">';
        echo '          <p>' . $statusMap['Shipped'] . '</p>';
        echo '          <h4>Shipped</h4>';
        echo '      </div>';
        echo '      <div class="status-item">';
        echo '          <p>' . $statusMap['Delivered'] . '</p>';
        echo '          <h4>Delivered</h4>';
        echo '      </div>';
        echo '      <div class="status-item">';
        echo '          <p>' . $statusMap['Cancelled'] . '</p>';
        echo '          <h4>Cancelled</h4>';
        echo '      </div>';
        echo '  </div>';
        echo '</div>';
        echo '</div>'; // End of stats div

        // Fetch and Sort Orders based on user selection
        $sortOrder = isset($_GET['sortOrder']) ? $_GET['sortOrder'] : 'ASC';
        $statusFilter = isset($_GET['statusFilter']) ? $_GET['statusFilter'] : '';

        $orderQuery = "SELECT * FROM orders";
        if ($statusFilter) {
            $orderQuery .= " WHERE order_Status = :statusFilter";
        }
        $orderQuery .= " ORDER BY order_Date $sortOrder";
        
        $stmt = $pdo->prepare($orderQuery);
        if ($statusFilter) {
            $stmt->bindParam(':statusFilter', $statusFilter);
        }
        $stmt->execute();
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Table Structure
        echo '<div class="table-box" style="position: relative;">';

        // Order Status Checker
        echo '<div class="order-status-checker" style="position: absolute;  right: 9px;">';
        echo '<form method="GET" action="" style="display: flex; width: 100%; align-items: center;">';
        echo '<input type="text" name="orderID" id="orderID" value="" placeholder="Enter Order ID" required>';
        echo '<div class="output-box">';
        if (isset($_GET['orderID'])) {
            $orderID = $_GET['orderID'];

            // Query to fetch the order status based on the entered order ID
            $stmt = $pdo->prepare("SELECT order_Status FROM orders WHERE order_ID = :orderID");
            $stmt->bindParam(':orderID', $orderID, PDO::PARAM_INT);
            $stmt->execute();
            $orderStatus = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($orderStatus) {
                $statusClass = '';
                switch ($orderStatus['order_Status']) {
                    case 'Pending':
                        $statusClass = 'pending';
                        break;
                    case 'Shipped':
                        $statusClass = 'shipped';
                        break;
                    case 'Cancelled':
                        $statusClass = 'cancelled';
                        break;
                    case 'Delivered':
                        $statusClass = 'delivered';
                        break;
                }

                echo '<span class="stat-item ' . $statusClass . '">' . $orderStatus['order_Status'] . '</span>';
            } else {
                echo '<span>Order ID not found</span>';
            }
        } else {
            echo '<span>&nbsp;</span>'; // Placeholder for empty output
        }
        echo '</div>';
        echo '<button type="submit" style="margin-left: 15px;">Check Status</button>';
        echo '</form>';
        echo '</div>';

        
        //Orders Table       
        echo '<h3>Orders</h3>';

        // Combo box for sorting by date and filtering by status
        echo '<form method="get" action="">';
        echo '<div class="filter-container">';
        echo '<label for="sortOrder">Sort By Date: </label>';
        echo '<select name="sortOrder" id="sortOrder" onchange="this.form.submit()">';
        echo '<option value="ASC" ' . ($sortOrder == 'ASC' ? 'selected' : '') . '>Ascending</option>';
        echo '<option value="DESC" ' . ($sortOrder == 'DESC' ? 'selected' : '') . '>Descending</option>';
        echo '</select>';

        echo '<label for="statusFilter" style="margin-left: 20px;">Status: </label>';
        echo '<select name="statusFilter" id="statusFilter" onchange="this.form.submit()">';
        echo '<option value="">All</option>';
        echo '<option value="Pending" ' . ($statusFilter == 'Pending' ? 'selected' : '') . '>Pending</option>';
        echo '<option value="Shipped" ' . ($statusFilter == 'Shipped' ? 'selected' : '') . '>Shipped</option>';
        echo '<option value="Delivered" ' . ($statusFilter == 'Delivered' ? 'selected' : '') . '>Delivered</option>';
        echo '<option value="Cancelled" ' . ($statusFilter == 'Cancelled' ? 'selected' : '') . '>Cancelled</option>';
        echo '</select>';
        echo '</div>';
        echo '</form>';

        echo '<table>';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Order ID</th>';
        echo '<th>Customer Name</th>';
        echo '<th>Address</th>';
        echo '<th>Quantity</th>';
        echo '<th>Date</th>';
        echo '<th>Total</th>';
        echo '<th>Status</th>';
        echo '<th>Actions</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        // Loop through the orders to display them in the table
        foreach ($orders as $order) {
            // Assign a class to each status for styling
            $statusClass = '';
            switch ($order['order_Status']) {
                case 'Pending':
                    $statusClass = 'pending';
                    break;
                case 'Shipped':
                    $statusClass = 'shipped';
                    break;
                case 'Cancelled':
                    $statusClass = 'cancelled';
                    break;
                case 'Delivered':
                    $statusClass = 'delivered';
                    break;
            }

            echo "<tr>
                    <td>{$order['order_ID']}</td>
                    <td>{$order['order_CustName']}</td>
                    <td>{$order['order_Address']}</td>
                    <td>{$order['order_Quantity']}</td>
                    <td>{$order['order_Date']}</td>
                    <td>₱{$order['order_Total']}</td>
                    <td><span class='stat-item $statusClass'>{$order['order_Status']}</span></td>
                    <td>
                        <a href='index.php?page=order_detail&order_ID={$order['order_ID']}'>View</a>
                    </td>
                </tr>";
        }

        echo '</tbody>';
        echo '</table>';

        echo '</div>';
        echo '</section>';
        

    } elseif ($page === 'inventory') {


            // Fetch statistics for boxes
            $totalProducts = $pdo->query("SELECT COUNT(*) AS total FROM product")->fetch(PDO::FETCH_ASSOC)['total'];
            $totalPeripherals = $pdo->query("SELECT COUNT(*) AS total FROM product WHERE prod_Category = 'Peripherals'")->fetch(PDO::FETCH_ASSOC)['total'];
            $totalCollectibles = $pdo->query("SELECT COUNT(*) AS total FROM product WHERE prod_Category = 'Collectibles'")->fetch(PDO::FETCH_ASSOC)['total'];
            $totalGames = $pdo->query("SELECT COUNT(*) AS total FROM product WHERE prod_Category = 'Games'")->fetch(PDO::FETCH_ASSOC)['total'];

            // Display statistics boxes
            echo '<div class="stats">';
            echo '<div class="box">';
            echo '<h3>Total Products</h3>';
            echo '<p>' . htmlspecialchars($totalProducts) . '</p>';
            echo '</div>';
            echo '<div class="box">';
            echo '<h3>Total Peripherals</h3>';
            echo '<p>' . htmlspecialchars($totalPeripherals) . '</p>';
            echo '</div>';
            echo '<div class="box">';
            echo '<h3>Total Collectibles</h3>';
            echo '<p>' . htmlspecialchars($totalCollectibles) . '</p>';
            echo '</div>';
            echo '<div class="box">';
            echo '<h3>Total Games</h3>';
            echo '<p>' . htmlspecialchars($totalGames) . '</p>';
            echo '</div>';
            echo '</div>';

        

            echo '<section id="inventory">';
            echo '<div id="table-container">';

            // "Inventory" title and filter dropdown
            echo '<h2>Inventory</h2>';
            echo '<div class="filter-container">
                <label for="category-filter">Filter by Category:</label>
                <select id="category-filter" onchange="filterTable()">
                    <option value="all">All</option>
                    <option value="Peripherals">Peripherals</option>
                    <option value="Collectibles">Collectibles</option>
                    <option value="Games">Games</option>
                </select>

                <label for="stock-status-filter" style="margin-left: 20px;">Stock Status:</label>
                <select id="stock-status-filter" onchange="filterTable()">
                    <option value="all">All</option>
                    <option value="in-stock">In Stock</option>
                    <option value="out-of-stock">Out of Stock</option>
                </select>

                <button id="add-product-btn" onclick="location.href=\'' . htmlspecialchars('index.php?page=add_product') . '\'">Add New Product</button>

            </div>';


            // Table structure
            echo '<table id="inventory-table">
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Brand</th>
                        <th>Subcategory</th>
                        <th>Quantity</th>
                        <th>Price</th>
                        <th>Stock Status</th>
                        <th>Average Rating</th>
                        <th>Actions</th>
                    </tr>';

                    while ($product = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $stockStatus = $product['prod_Quantity'] > 0 ? 'in-stock' : 'out-of-stock';
                    $circleColor = $product['prod_Quantity'] > 0 ? 'green' : 'red';

                    // Display each product row
                    echo '<tr data-category="' . htmlspecialchars($product['prod_Category']) . '" data-stock-status="' . htmlspecialchars($stockStatus) . '">
                    <td>' . htmlspecialchars($product['prod_Id']) . '</td>
                    <td>' . htmlspecialchars($product['prod_Name']) . '</td>
                    <td>' . htmlspecialchars($product['prod_Desc']) . '</td>
                    <td>' . htmlspecialchars($product['prod_Brand']) . '</td>
                    <td>' . htmlspecialchars($product['prod_SubCategory']) . '</td>
                    <td>' . htmlspecialchars($product['prod_Quantity']) . '</td>
                    <td>' . htmlspecialchars($product['prod_Price']) . '</td>
                    <td>
                        <span class="stock-status">
                            <span class="circle" style="background-color: ' . $circleColor . ';"></span>
                            ' . ($stockStatus === 'in-stock' ? 'In Stock' : 'Out of Stock') . '
                        </span>
                    </td>
                    <td>' . htmlspecialchars(number_format($product['avg_rating'], 2)) . '</td>
                    <td>
                    
                        <button class="edit-btn" onclick="location.href=\'index.php?page=edit_product&prod_Id=' . $product['prod_Id'] . '\'">
                            <img src="edit.png" alt="Edit" />
                        </button>

                        <button class="view-rating-btn" onclick="location.href=\'index.php?page=rate_product&prod_Id=' . $product['prod_Id'] . '\'">
                            <img src="rating.png" alt="View Rating" />
                        </button>
                        
                        <button class="delete-btn" onclick="deleteProduct(' . $product['prod_Id'] . ')">
                            <img src="delete.png" alt="Delete" />
                        </button>
                    </td>
                </tr>';
                }
                    echo '</table>';
            echo '</div>'; 
            echo '</section>';

        }    
        echo '<script>
        document.addEventListener("DOMContentLoaded", function () {
            // Check if the filters exist before adding event listeners
            const categoryFilter = document.getElementById("category-filter");
            const stockFilter = document.getElementById("stock-status-filter");

            if (categoryFilter && stockFilter) {
                function filterTable() {
                    const categoryFilterValue = categoryFilter.value.toLowerCase();
                    const stockFilterValue = stockFilter.value.toLowerCase();
                    const rows = document.querySelectorAll("#inventory-table tr[data-category]");

                    rows.forEach(row => {
                        const category = row.getAttribute("data-category").toLowerCase();
                        const stockStatus = row.getAttribute("data-stock-status").toLowerCase();

                        const categoryMatch = categoryFilterValue === "all" || category === categoryFilterValue;
                        const stockMatch = stockFilterValue === "all" || stockStatus === stockFilterValue;

                        row.style.display = (categoryMatch && stockMatch) ? "" : "none";
                    });
                }

                categoryFilter.addEventListener("change", filterTable);
                stockFilter.addEventListener("change", filterTable);
            }
        
            
        });

        function deleteProduct(productId) {
            if (confirm("Are you sure you want to delete this product?")) {
            
                const xhr = new XMLHttpRequest();
                xhr.open("POST", "index.php", true);
                xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");

                xhr.send("delete_prod_Id=" + productId);

                xhr.onload = function() {
                    if (xhr.status === 200) {
                        const response = JSON.parse(xhr.responseText);

                        if (response.status === "success") {
                            alert(response.message); 
                            
                            if (response.reload) {
                                window.location.reload(); 
                            }
                        } else {
                            alert(response.message);
                        }
                    } else {
                        alert("Error: Could not delete the product. Please try again.");
                    }
                };
            }
        }

    </script>';

    //View Rating Page
    if ($page === 'rate_product' && isset($_GET['prod_Id'])) {
            $prod_Id = intval($_GET['prod_Id']);


            echo '<style>
                #ratings {
                    border: 2px solid #E2E1E1;
                    border-radius: 8px;
                    padding: 20px;
                    margin-top: 20px;
                    position: relative;
                }

                #ratings-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 20px;
                    border: 2px solid #003366;
                    border-radius: 8px;
                    overflow: hidden;
                }

                #ratings-table th, #ratings-table td {
                    padding: 12px 15px;
                    text-align: left;
                    border-bottom: 2px solid #ddd;
                }

                #ratings-table th {
                    background-color: #003366;
                    color: white;
                }

                #ratings-table tr:last-child td {
                    border-bottom: none;
                }

                #ratings-table tr:hover {
                    background-color: #f2f2f2;
                }
                    

            </style>';

            // Fetch product ratings
            $stmt = $pdo->prepare("SELECT rev_Rating, rev_Feedback, rev_DateSubmitted, rev_UserName 
            FROM reviews WHERE prod_Id = :prod_Id");
            $stmt->bindParam(':prod_Id', $prod_Id, PDO::PARAM_INT);
            $stmt->execute();
            $ratings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate average rating
            $avgStmt = $pdo->prepare("SELECT AVG(rev_Rating) AS avg_rating FROM reviews WHERE prod_Id = :prod_Id");
            $avgStmt->bindParam(':prod_Id', $prod_Id, PDO::PARAM_INT);
            $avgStmt->execute();
            $averageRating = $avgStmt->fetch(PDO::FETCH_ASSOC)['avg_rating'];

            echo '<section id="ratings">';
            echo '<h2>Ratings for Product ID ' . htmlspecialchars($prod_Id) . '</h2>';

            // Average rating display
            echo '<div class="stats">';
            echo '<div class="box">';
            echo '<h3>Average Rating</h3>';
            echo '<p>' . (is_null($averageRating) ? 'No Ratings' : number_format($averageRating, 2)) . '</p>';
            echo '</div>';
            echo '</div>';

            // Ratings table
            if (count($ratings) > 0) {
            echo '<table id="ratings-table">';
            echo '<tr>
            <th>Rating</th>
            <th>Feedback</th>
            <th>Date Submitted</th>
            <th>User Name</th>
            </tr>';

            foreach ($ratings as $rating) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($rating['rev_Rating']) . '</td>';
            echo '<td>' . htmlspecialchars($rating['rev_Feedback']) . '</td>';
            echo '<td>' . htmlspecialchars($rating['rev_DateSubmitted']) . '</td>';
            echo '<td>' . htmlspecialchars($rating['rev_UserName']) . '</td>';
            echo '</tr>';
            }
            echo '</table>';
            } else {
            echo '<p>No ratings available for this product.</p>';
            }
            echo '<button onclick="history.back()" style="
                display: inline-block;
                padding: 10px 20px;
                font-size: 16px;
                font-weight: bold;
                color: white;
                background-color: #003366;
                border: none;
                border-radius: 5px;
                cursor: pointer;
                margin-top: 20px;
                transition: background-color 0.3s ease;
            " onmouseover="this.style.backgroundColor=\'#00509E\'" onmouseout="this.style.backgroundColor=\'#003366\'">
                Go Back
            </button>';
        
            echo '</section>';
        }
    
    // View Per Order Page
    if (isset($_GET['order_ID'])) {
        $orderID = $_GET['order_ID'];

        // Fetch order details
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_ID = :orderID");
        $stmt->bindParam(':orderID', $orderID, PDO::PARAM_INT);
        $stmt->execute();
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($order) {
            echo '<section id="order-detail">';
            echo '<h2>Order Details for Order ID: ' . htmlspecialchars($orderID) . '</h2>';

            echo '<div class="table-container" style="overflow-x: auto; margin-top: 10px;">';
            // Display order information
            echo '<table>';
            echo '<thead>';
            echo '<tr>';
            echo '<th>Order ID</th>';
            echo '<th>Customer Name</th>';
            echo '<th>Address</th>';
            echo '<th>Quantity</th>';
            echo '<th>Date</th>';
            echo '<th>Total</th>';
            echo '<th>Status</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';

            echo '<style>
            .stat-item {
                    display: inline-block;
                    padding: 5px 15px;
                    border-radius: 20px;
                    color: white;
                    font-size: 0.9em;
                    text-align: center;
                    color: black;
                    border: 1px solid black; 
                    font-weight: bold;
                    text-transform: lowercase;
                }

            .stat-item.pending {
                background-color: #FFA500; /* Orange for Pending */
            }

            .stat-item.shipped {
                background-color: #3CD040; /* Green for Shipped */
            }

            .stat-item.cancelled {
                background-color: #FF2205; /* Red for Cancelled */
            }

            .stat-item.delivered {
                background-color: #4A90E2; /* Blue for Delivered */
            }

            .table-container {
                max-width: 100%;
                border: 2px solid #ddd;
                border-radius: 8px;
                padding: 20px;
            }

            .tracking-info-container {
                margin-top: 20px;
                padding: 15px;
                border: 2px solid #ddd;
                border-radius: 8px;
                background-color: #f8f8f8;
            }

            .status-update-container button {
                padding: 10px 20px;
                margin: 5px;
                border: none;
                border-radius: 5px;
                color: white;
                font-weight: bold;
            }

            .status-update-container .shipped {
                background-color: #3CD040;
            }

            .status-update-container .delivered {
                background-color: #4A90E2;
            }

            .status-update-container .cancelled {
                background-color: #FF2205;
            }

            .back-to-orders {
                display: inline-block;
                margin-top: 20px;
                padding: 10px 20px;
                font-size: 16px;
                font-weight: bold;
                text-decoration: none;
                color: #fff;
                background-color: #0D3B66; 
                border-radius: 5px;
                border: 1px solid #0056b3; 
                transition: all 0.3s ease;
            }

            .back-to-orders:hover {
                background-color: #0056b3; 
                border-color: #004085; 
                color: #e9ecef; 
                text-decoration: none;
                box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2); 
            }

            </style>';

            // Assign a class to the status for styling
            $statusClass = '';
            $trackingMessage = '';

            switch ($order['order_Status']) {
                case 'Pending':
                    $statusClass = 'pending';
                    $trackingMessage = 'The order has been received but is still pending. Please ensure all details are correct before proceeding to shipment.';
                    break;
                case 'Shipped':
                    $statusClass = 'shipped';
                    $trackingMessage = 'The shipping process has started, and the customer has been notified. Ensure timely delivery.';
                    break;
                case 'Cancelled':
                    $statusClass = 'cancelled';
                    $trackingMessage = 'This order has been cancelled. No further action is required.';
                    break;
                case 'Delivered':
                    $statusClass = 'delivered';
                    $trackingMessage = 'The order has been successfully delivered to the customer. Please update inventory and close the order process.';
                    break;
            }

            echo "<tr>
                    <td>{$order['order_ID']}</td>
                    <td>{$order['order_CustName']}</td>
                    <td>{$order['order_Address']}</td>
                    <td>{$order['order_Quantity']}</td>
                    <td>{$order['order_Date']}</td>
                    <td>₱{$order['order_Total']}</td>
                    <td><span class='stat-item $statusClass'>{$order['order_Status']}</span></td>
                </tr>";
            echo '</tbody>';
            echo '</table>';

            // Tracking Information Box
            echo '<div class="tracking-info-container">';
            echo '<h3>Tracking Information</h3>';
            echo '<p>' . $trackingMessage . '</p>';
            echo '</div>';

            // Status Update Buttons
            echo '<div class="status-update-container">';
            echo '<form action="" method="post">';
            echo '<input type="hidden" name="order_ID" value="' . $orderID . '">';
            echo '<button type="submit" name="status" value="Shipped" class="shipped">Mark as Shipped</button>';
            echo '<button type="submit" name="status" value="Delivered" class="delivered">Mark as Delivered</button>';
            echo '<button type="submit" name="status" value="Cancelled" class="cancelled">Mark as Cancelled</button>';
            echo '</form>';
            echo '</div>';

            echo '</div>';
            echo '<a href="index.php?page=orders" class="back-to-orders">Back to Orders</a>';
            echo '</section>';
        } else {
            echo '<p>Order not found.</p>';
        }
    } else {
    
    } //Ending

    // Handle status update if form is submitted
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['status'], $_POST['order_ID'])) {
        $status = $_POST['status'];
        $orderID = $_POST['order_ID'];

        // Update the order status in the database
        $stmt = $pdo->prepare("UPDATE orders SET order_Status = :status WHERE order_ID = :orderID");
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':orderID', $orderID, PDO::PARAM_INT);

        if ($stmt->execute()) {
            // Redirect back to the order page with updated status
            header("Location: index.php?page=order_detail&order_ID=" . $orderID);
            exit;
        } else {
            echo "Error updating status.";
        }
    } //Ending

        
    //Adding Page
    if ($page === 'add_product') {

        echo '<style>
            .add-container {
                max-width: 100%;
                margin: 40px auto;
                padding: 30px;
                background-color:rgb(255, 255, 255);
                border-radius: 12px;
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
                font-family: "Roboto", sans-serif;
            }

            .add-container h2 {
                text-align: center;
                color: #003366;
                font-size: 28px;
                margin-bottom: 25px;
            }

            .add-container label {
                font-weight: bold;
                font-size: 16px;
                margin-bottom: 10px;
                display: block;
            }

            .add-container .form-row {
                display: flex;
                gap: 20px;
                margin-bottom: 20px;
            }

            .add-container .form-row .form-field {
                flex: 1; 
            }

            .add-container input[type="text"],
            .add-container input[type="number"],
            .add-container select,
            .add-container textarea {
                width: 100%;
                padding: 12px;
                border: 1px solid #ccc;
                border-radius: 6px;
                font-size: 15px;
                box-sizing: border-box;
                transition: all 0.3s ease;
                margin-bottom: 20px;
            }


            .add-container input[type="text"]:focus,
            .add-container input[type="number"]:focus,
            .add-container textarea:focus,
            .add-container select:focus {
                border-color: #0055cc;
                outline: none;
                box-shadow: 0 0 5px rgba(0, 85, 204, 0.5);
            }

            .add-container button[type="submit"] {
                background-color: #003366;
                color: white;
                border: none;
                padding: 10px 15px;
                border-radius: 4px;
                font-size: 18px;
                cursor: pointer;
            }

            .add-container button[type="submit"]:hover {
                background-color: #0055cc;
            }

            .add-container .cancel-btn {
                background-color: #c0392b;
                color: white;
                border: none;
                padding: 10px 15px;
                border-radius: 4px;
                font-size: 18px;
                cursor: pointer;
            }

            .add-container .cancel-btn:hover {
                background-color: #e74c3c;
            }

            /* Responsive Design */
            @media (max-width: 768px) {
                .add-container {
                    padding: 20px;
                }

                .add-container .form-row {
                    flex-direction: column;
                    gap: 15px;
                }
            }
        </style>';

        echo '<div class="add-container">
            <h2>Add New Product</h2>
            <form method="POST" action="index.php?page=add_product" enctype="multipart/form-data">
                <label for="name">Product Name:</label>
                <input type="text" id="name" name="name" required>

                <label for="description">Description:</label>
                <textarea id="description" name="description" required></textarea>

                <label for="brand">Brand:</label>
                <input type="text" id="brand" name="brand" required>

                <div class="form-row">
                        <div class="form-field">
                            <label for="category">Category</label>
                                <select id="category" name="category" required>
                                    <option value="Peripherals">Peripherals</option>
                                    <option value="Collectibles">Collectibles</option>
                                    <option value="Games">Games</option>
                                </select>
                        </div>
            
                        <div class="form-field">
                            <label for="subcategory">Subcategory</label>
                            <input type="text" id="subcategory" name="subcategory" class="full-width" />
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-field">
                            <label for="quantity">Quantity</label>
                            <input type="number" id="quantity" name="quantity" />
                        </div>
                        <div class="form-field">
                            <label for="price">Price</label>
                            <input type="number" id="price" name="price" />
                        </div>
                    </div>

                <label for="prod_Image">Product Image:</label>
                <input type="file" id="prod_Image" name="prod_Image" accept="image/*" required><br><br>

                <button type="submit">Add Product</button>
                <a href="index.php?page=inventory" class="cancel-btn">Cancel</a>
            </form>
        </div>
        ';
        }

        if ($_SERVER['REQUEST_METHOD'] == 'POST' && $page === 'add_product') {
            // Retrieve form data
            $prod_Name = $_POST['name'];
            $prod_Desc = $_POST['description'];
            $prod_Brand = $_POST['brand'];
            $prod_SubCategory = $_POST['subcategory'];
            $prod_Quantity = intval($_POST['quantity']);
            $prod_Price = floatval($_POST['price']);
            $prod_StockStatus = $prod_Quantity > 0 ? 'in-stock' : 'out-of-stock'; // Calculate stock status based on quantity
        
            // Print $_POST array for debugging
            echo '<pre>';
            print_r($_POST);
            echo '</pre>';
        
            // Initialize image path
            $prod_ImagePath = '';
        
            // Handle image upload
            if (isset($_FILES['prod_Image']) && $_FILES['prod_Image']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = 'uploads/';
                $uploadFile = $uploadDir . basename($_FILES['prod_Image']['name']);
                $imageFileType = strtolower(pathinfo($uploadFile, PATHINFO_EXTENSION));
        
                // Validate image file type
                $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'];
                if (in_array($imageFileType, $allowedTypes)) {
                    if (move_uploaded_file($_FILES['prod_Image']['tmp_name'], $uploadFile)) {
                        $prod_ImagePath = $uploadFile; // Set image path
                    } else {
                        echo '<p>Error uploading image.</p>';
                        exit;
                    }
                } else {
                    echo '<p>Invalid image file type. Allowed types: jpg, jpeg, png, gif.</p>';
                    exit;
                }
            }
        
            try {
                // Insert product into database
                $stmt = $pdo->prepare("INSERT INTO product (prod_Name, prod_Desc, prod_Brand, prod_Category, prod_SubCategory, prod_Quantity, prod_Price, prod_StockStatus, prod_Image) 
                                       VALUES (:prod_Name, :prod_Desc, :prod_Brand, :prod_Category, :prod_SubCategory, :prod_Quantity, :prod_Price, :prod_StockStatus, :prod_Image)");
                $stmt->execute([
                    ':prod_Name' => $prod_Name,
                    ':prod_Desc' => $prod_Desc,
                    ':prod_Brand' => $prod_Brand,
                    ':prod_Category' => $_POST['category'],  // Ensure category is passed correctly
                    ':prod_SubCategory' => $prod_SubCategory,
                    ':prod_Quantity' => $prod_Quantity,
                    ':prod_Price' => $prod_Price,
                    ':prod_StockStatus' => $prod_StockStatus, // Bind stock status here
                    ':prod_Image' => $prod_ImagePath,
                ]);
        
                // Redirect back to the inventory page after adding the product
                header("Location: index.php?page=inventory");
                exit();
            } catch (PDOException $e) {
                echo '<p>Error: ' . $e->getMessage() . '</p>';
            }
        }
        
        


    
    //Editing Page
    if (isset($_GET['page']) && $_GET['page'] == 'edit_product') {
        // Check if 'prod_Id' is provided in the URL
        if (isset($_GET['prod_Id'])) {
            $prodId = $_GET['prod_Id'];
    
            // Fetch product details from the database
            $stmt = $pdo->prepare("SELECT * FROM product WHERE prod_Id = :prod_Id");
            $stmt->execute(['prod_Id' => $prodId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
            // Check if product exists
            if ($product) {

                echo '<style>
                        body {
                            font-family: Roboto, sans-serif;
                            background-color: #f9f9f9;
                            margin: 0;
                            padding: 0;
                        }
                        .edit-container {
                            max-width: 100%;
                            background: #ffffff;
                            padding: 20px;
                            border-radius: 8px;
                            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                        }
                        .edit-container h2 {
                            text-align: center;
                            margin-bottom: 20px;
                            color: #003366;
                        }
                        .edit-container label {
                            display: block;
                            margin-bottom: 8px;
                            font-weight: bold;
                            color: #333;
                        }
                        .edit-container input, .edit-container textarea {
                            width: calc(100% - 30px); 
                            padding: 10px;
                            margin-bottom: 20px;
                            border: 1px solid #ccc;
                            border-radius: 4px;
                            font-size: 16px;
                        }
                        .edit-container button {
                            background-color: #003366;
                            color: white;
                            border: none;
                            padding: 10px 15px;
                            border-radius: 4px;
                            font-size: 16px;
                            cursor: pointer;
                        }
                        .edit-container button:hover {
                            background-color: #005b99;
                        }
                        .edit-container .cancel-btn {
                            background-color: #e74c3c;
                            margin-left: 10px;
                            color: white;
                            border: none;
                            padding: 10px 15px;
                            border-radius: 4px;
                            font-size: 16px;
                            cursor: pointer;
                        }
                        .edit-container .cancel-btn:hover {
                            background-color: #c0392b;
                            color: white;
                            border: none;
                            padding: 10px 15px;
                            border-radius: 4px;
                            font-size: 16px;
                            cursor: pointer;
                        }
                    </style>';

                    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_product'])) {
                        // Retrieve the updated form data
                        $prod_Name = isset($_POST['prod_Name']) ? $_POST['prod_Name'] : '';
                        $prod_Desc = isset($_POST['prod_Desc']) ? $_POST['prod_Desc'] : '';
                        $prod_Brand = isset($_POST['prod_Brand']) ? $_POST['prod_Brand'] : '';
                        $prod_Quantity = isset($_POST['prod_Quantity']) ? $_POST['prod_Quantity'] : '';
                        $prod_Price = isset($_POST['prod_Price']) ? $_POST['prod_Price'] : '';
                        $prodId = isset($_POST['prod_Id']) ? $_POST['prod_Id'] : '';
                        $prod_ImagePath = $product['prod_Image']; 
                    
                        // Handle new image upload
                        if (isset($_FILES['prod_Image']) && $_FILES['prod_Image']['error'] === UPLOAD_ERR_OK) {
                            $uploadDir = 'uploads/';
                            $uploadFile = $uploadDir . basename($_FILES['prod_Image']['name']);
                            $imageFileType = strtolower(pathinfo($uploadFile, PATHINFO_EXTENSION));
                    
                            $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'];
                            if (in_array($imageFileType, $allowedTypes)) {
                                if (move_uploaded_file($_FILES['prod_Image']['tmp_name'], $uploadFile)) {
                                    $prod_ImagePath = $uploadFile;
                                } else {
                                    echo '<p>Error uploading new image.</p>';
                                }
                            } else {
                                echo '<p>Invalid image file type. Allowed types: jpg, jpeg, png, gif.</p>';
                            }
                        }
                    
                        // Recalculate stock status based on quantity
                        $prod_StockStatus = $prod_Quantity > 0 ? 'in-stock' : 'out-of-stock';
                    
                        try {
                            // Update the product record with the new details, including stock status
                            $updateStmt = $pdo->prepare("
                                UPDATE product 
                                SET prod_Name = :prod_Name, 
                                    prod_Desc = :prod_Desc, 
                                    prod_Brand = :prod_Brand, 
                                    prod_Quantity = :prod_Quantity, 
                                    prod_Price = :prod_Price, 
                                    prod_Image = :prod_Image, 
                                    prod_StockStatus = :prod_StockStatus
                                WHERE prod_Id = :prod_Id
                            ");
                            
                            $update_success = $updateStmt->execute([
                                ':prod_Name' => $prod_Name,
                                ':prod_Desc' => $prod_Desc,
                                ':prod_Brand' => $prod_Brand,
                                ':prod_Quantity' => $prod_Quantity,
                                ':prod_Price' => $prod_Price,
                                ':prod_Image' => $prod_ImagePath,
                                ':prod_StockStatus' => $prod_StockStatus, // Update stock status
                                ':prod_Id' => $prodId
                            ]);
                    
                            if ($update_success) {
                                header('Location: index.php?page=inventory');
                                exit();
                            } else {
                                echo "Failed to update the product. Please try again.";
                            }
                        } catch (Exception $e) {
                            echo "An error occurred: " . $e->getMessage();
                        }
                    }
                    

                // Display the product editing form
                echo '<div class="edit-container">
                        <h2>Edit Product</h2>
                        <form method="POST" action="" enctype="multipart/form-data">

                            <input type="hidden" name="prod_Id" value="' . htmlspecialchars($product['prod_Id']) . '">

                            <label for="prod_Name">Product Name:</label>
                            <input type="text" name="prod_Name" id="prod_Name" value="' . htmlspecialchars($product['prod_Name']) . '" required>

                            <label for="prod_Desc">Product Description:</label>
                            <textarea name="prod_Desc" id="prod_Desc" rows="4" required>' . htmlspecialchars($product['prod_Desc']) . '</textarea>

                            <label for="prod_Brand">Product Brand:</label>
                            <input type="text" name="prod_Brand" id="prod_Brand" value="' . htmlspecialchars($product['prod_Brand']) . '" required>

                            <label for="prod_Quantity">Product Quantity:</label>
                            <input type="number" name="prod_Quantity" id="prod_Quantity" value="' . htmlspecialchars($product['prod_Quantity']) . '" required>

                            <label for="prod_Price">Product Price:</label>
                            <input type="number" step="0.01" name="prod_Price" id="prod_Price" value="' . htmlspecialchars($product['prod_Price']) . '" required>

                            <label for="prod_Image">Current Image:</label>';

                            if (!empty($product['prod_Image'])) {
                                echo '<img src="' . htmlspecialchars($product['prod_Image']) . '" alt="' . htmlspecialchars($product['prod_Name']) . '" style="width: 100px; height: 100px; display: block; margin-bottom: 10px;">';
                            } else {
                                echo '<p>No image uploaded.</p>';
                            }

                echo   '<label for="prod_Image">Change Image:</label>
                            <input type="file" name="prod_Image" id="prod_Image" accept="image/*">

                            <button type="submit" name="edit_product">Update Product</button>
                            <a href="index.php?page=inventory" class="cancel-btn">Cancel</a>
                        </form>
                    </div>';

            } else {
                echo "Product not found.";
            }
        } else {
            echo "No product ID provided.";
        }
    }


    } elseif ($_SESSION['user_role'] === 'user') {

        echo '<style>
        
        body {
            margin: 0;
            padding: 0;
            padding-top: 135px; /* Adjust this to account for the height of both top and nav bars (top bar: 85px + nav bar: 40px) */
        }

        /* Top Bar */
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            background-color: white;
            border-bottom: 1px solid #E2E1E1;
            width: 100%;
            position: fixed;
            top: 0;
            z-index: 1000;
        }

        .logo img {
            height: 60px;
        }

        .search-bar input {
            width: 500px;
            padding: 8px;
            border-radius: 20px;
            border: 1px solid #ccc;
            font-size: 1em;
            margin-right: 100px;
        }

        .profile img {
            height: 25px;
            margin-right:20px;
        }

        /* Navigation Bar */
        .nav-bar {
            background-color: #003366;
            border-bottom: 1px solid #E2E1E1;
            position: fixed;
            top: 85px; /* Adjust based on the top bar height */
            width: 100%;
            z-index: 999;
            height: 40px;
        }

        .nav-bar nav {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100%;
        }

        .nav-bar nav a {
            text-decoration: none;
            padding: 10px;
            font-weight: bold;
            color: white;
            transition: background-color 0.3s ease;
        }

        .nav-bar nav a:hover {
            background-color: rgb(0, 0, 0);
            color: white;
        }

        /* Main Content */
        .content {
            display: flex;
            justify-content: space-between;
            padding: 20px;
        }

        /* Filter Box */
        .filter-box {
            margin-left: 20px;
            width: 250px;  
            height: 650px; 
            background-color: #f4f4f4;
            padding: 15px; /* Adjust padding */
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            box-sizing: border-box; 
        }

        /* Header */
        .filter-box h3 {
            font-size: 18px;
            margin-bottom: 10px;
            margin-top: 5px;
        }

        .filter-box label {
            display: flex;  
            align-items: center; 
            font-size: 14px;
            margin-bottom: 0px; 
            margin-left: 0
        }

        .filter-box input[type="checkbox"] {
            margin-right: 3px;
             
        }

        .filter-box input[type="number"] {
            width: 100%;  /* Make input boxes fill the width */
            padding: 5px;
            margin-top: 5px;
            font-size: 14px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }

       /* Button Container (for Apply and Clear filters) */
        .filter-buttons {
            display: flex; /* Align buttons in a row */
            gap: 10px; /* Space between buttons */
            margin-top: 10px; /* Space between filter options and buttons */
        }

        /* Apply Button */
        .filter-box button {
            padding: 10px;
            font-size: 14px;
            background-color: #003366;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
        }

        .filter-box button:hover {
            background-color: #005b99;
        }

        /* Clear Filters Button */
        .filter-box .clear-filters {
            display: inline-block; /* Make it appear like a button */
            padding: 10px;
            font-size: 14px;
            background-color:rgb(222, 231, 250); /* Light background for the clear button */
            color: #003366;
            border: 1px solid #003366;
            border-radius: 4px;
            text-align: center;
            cursor: pointer;
            width: 30%;
            text-decoration: none;
        }

        /* Product Grid */
        .product-grid {
            width: 70%;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }

        /* Product Container */
        .product-container {
            flex-grow: 1; 
            background-color: #D9D9D9;
            padding: 20px;
            border-radius: 8px;
            box-sizing: border-box;
            margin-left: 20px;
            margin-right: 10px;
        }

        /* Product Grid */
        .product-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(200px, 1fr)); /* Responsive grid */
            gap: 28px;
        }


        /* Product Box */
        .product-box {
            background-color: #ffffff;
            padding: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            text-align: left;
        }

        .product-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
        }

        .product-box h4 {
            font-size: 18px;
            margin: 0px 0;
        }

        .product-box h3 {
            font-size: 22px;
            margin: 0px 0;
            color: #0D3B66;
        }

        .product-box p {
            color: #555;
            font-size: 16px;
        }

        .product-rating {
            width: 100px;
            height: 18px;
        }

        .product-box:hover {
            background-color:rgba(0, 0, 0, 0.32); 
            transform: scale(1.05); /* Slightly zoom in the product box */
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15); /* Add a shadow on hover */
            transition: transform 0.3s ease, box-shadow 0.3s ease; /* Smooth transition */
            cursor: pointer; /* Change cursor to pointer to indicate clickability */
        }

        .product-box:hover img {
            transform: scale(1.1); /* Slightly zoom in the image on hover */
            transition: transform 0.3s ease; /* Smooth transition for image */
        }

        .product-detail-container {
            display: flex;
            justify-content: center;
            align-items: flex-start;
            gap: 20px;
            padding: 20px;
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            max-width: 1200px;
            margin: 50px auto;
        }

        .product-image-box {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            background-color: #f4f4f4;
            border-radius: 8px;
            padding: 20px;
            height: 500px; /* Adjust for consistent layout */
        }

        .product-image-box img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            border-radius: 8px;
        }

        .product-details-box {
            flex: 1.5;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            padding: 20px;
        }

        .product-details-box h1 {
            font-size: 40px;
            margin-bottom: 10px;
            color: #333;
        }

        .product-details-box p {
            font-size: 16px;
            margin: 5px 0;
            color: #555;
        }

        .quantity-box {
            margin-top: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .quantity-box input[type="number"] {
            width: 60px;
            padding: 5px;
            font-size: 16px;
            text-align: center;
            border: 1px solid #ccc;
            border-radius: 4px;
        }

        .action-buttons {
            margin-top: 20px;
            display: flex;
            gap: 10px;
        }

        .action-buttons button {
            padding: 10px 20px;
            font-size: 16px;
            color: #ffffff;
            background-color: #007bff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        .action-buttons button:hover {
            background-color: #0056b3;
        }

        .action-buttons .cancel {
            background-color: #6c757d;
        }

        .action-buttons .cancel:hover {
            background-color: #495057;
        }

        .add-to-cart-button {
            display: inline-block;
            padding: 10px 20px;
            background-color:rgb(0, 21, 43); /* Dark blue background */
            color: white;
            font-size: 16px;
            font-weight: bold;
            text-align: center;
            text-decoration: none;
            border-radius: 5px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: background-color 0.3s ease, transform 0.2s ease;
        }

        .add-to-cart-button:hover {
            background-color: #002244; /* Slightly darker blue on hover */
            transform: scale(1.05); /* Slight zoom effect */
        }

        .add-to-cart-button:active {
            background-color: #001933; /* Even darker blue when clicked */
            transform: scale(1);
        }

        .add-to-cart-button:focus {
            outline: none;
            box-shadow: 0 0 0 2px rgba(0, 51, 102, 0.5); /* Blue focus border */
        }

        a.custom-link {
            color: #003366; 
            text-decoration: none; 
            transition: color 0.3s ease; 
        }

        a.custom-link:hover {
            color: #002244; 
        }

        a.custom-link:focus {
            outline: none; 
        }

        /* Stock Status */
        .in-stock, .out-of-stock {
            font-weight: bold;
            display: flex;
            align-items: center;
        }

        .in-stock .circle, .out-of-stock .circle {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 10px;
        }

        .in-stock {
            color: green;
        }

        .out-of-stock {
            color: red;
        }

        .disabled-link {
            color: gray;
            cursor: not-allowed; 
        }

        /* General styling for the cart page */
        .orders-page-container {
            display: flex;
            gap: 20px;
            margin: 20px;
            flex-wrap: wrap; 
            width: 100%
        }

        .cart-items {
            flex: 50%; /* This ensures the left box (cart-items) takes up at least 50% of the width */
            border: 1px solid #ddd;
            padding: 20px;
            border-radius: 5px;
            background-color: #fff;
        }

        .cart-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
        }

        .cart-row {
            display: flex;
            align-items: flex-start;
            border-bottom: 1px solid #ddd;
            padding: 20px 0;
            gap: 10px;
        }

        .cart-column {
            flex: 1;
            padding: 0px;
        }

        .cart-item-image {
            width: 100px;
            height: 100px;
            object-fit: cover;
            margin-right: 10px;
        }

        .cart-summary {
            flex: 1 1 10%; /* Adjust to fit layout */
            border: 1px solid #ddd;
            padding: 20px;
            border-radius: 5px;
            background-color: #fff;
            height: 380px;; 
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1); 
        }

        .cart-summary h3 {
            margin-bottom: 15px;
            font-size: 20px;
            font-weight: bold;
            color: #333;
        }

        .cart-summary p {
            margin: 10px 0;
            font-size: 16px;
            color: #555;
        }

        .shipping-option {
            margin-top: 20px;
            padding: 10px;
            background-color: #f9f9f9;
            border-radius: 5px;
            border: 1px solid #ccc;
        }

        .shipping-option h4 {
            margin-bottom: 5px;
            font-size: 16px;
            color: #003366;
        }

        .shipping-option p {
            margin: 5px 0;
            font-size: 14px;
            color: #666;
        }

        .checkout-button {
            display: block;
            width: 100%; /* Make it wider */
            margin: 20px auto 0;
            padding: 12px;
            background-color: #003366;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-align: center;
            font-size: 16px;
            font-weight: bold;
            transition: background-color 0.3s ease;
        }

        .checkout-button:hover {
            background-color: rgb(8, 16, 87);
        }

        .cart-column.product-info {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
            word-wrap: break-word;
            margin-right: 120px;
        }

        .cart-column.product-info p {
            flex: 1;
            margin: 0; 
            font-size: 18px; 
            font-weight: bold;
        }

        .checkout-page {
            width: 60%;
            margin: 0 auto;
            font-family: Roboto, sans-serif;
        }

        h2 {
            color: #003366;
        }

        .user-info, .order-summary, .payment-method {
            margin-bottom: 30px;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
        }

        .user-info p,
        .order-summary p {
            font-size: 18px;
            line-height: 1.5;
        }

        .info-container {
            display: flex;
            justify-content: space-between;
        }

        .left-side {
            flex: 1;
            padding: 10px;
            text-align: left;
        }

        .center {
            flex: 2;
            padding: 10px;
            text-align: center;
        }

        .right-side {
            flex: 1;
            padding: 10px;
            text-align: center;
        }


        .order-summary .cart-grid {
            display: grid;
            flex-direction: column;
            gap: 0px;
        }

       .cart-row {
            display: flex; 
            padding: 10px;
            background-color: #f9f9f9;
            width: 1070px;
            align-items: flex-start;
            gap: 200px;
        }

        .cart-column {
            text-align: left;
            margin-bottom: 10px;
        }

        .cart-column:nth-child(2), .cart-column:nth-child(4) {
            text-align: right;
        }

        .payment-method {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .payment-options {
            display: flex;
            flex-direction: column;
            width: 60%;
        }

        .payment-option {
            display: flex;
            align-items: left;
            margin-top: 25px;
        }

        .payment-method-img {
            margin-right: 10px;
        }

        .payment-details {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }

        .total-prices {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            width: 40%;
        }

        .total-prices p {
            margin-bottom: 5px;
        }

        button {
            margin-top: 20px;
        }


       .place-order-button {
            background-color: #003366;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin-left: 250px;
        }

        .place-order-button:hover {
            background-color: #005bb5;
        }




    </style>';

    echo '<div class="top-bar">
        <div class="logo"><img src="user_logo.png" alt="Logo"></div>
        <form method="GET" action="index.php"> 
            <div class="search-bar">
                <input type="text" placeholder="Search products...">
            </div>
        </form>
        <div class="profile">
            <a href="index.php?page=profile"><img src="user.png" alt="Profile"></a>
            <a href="' . $_SERVER['PHP_SELF'] . '?logout=true"><img src="logout.png" alt="Logout"></a>
        </div>
    </div>';

    echo '<div class="nav-bar">
        <nav>
            <a href="index.php?page=home">Home</a>
            <a href="index.php?page=peripherals">Peripherals</a>
            <a href="index.php?page=games">Games</a>
            <a href="index.php?page=collectibles">Collectibles</a>
            <a href="index.php?page=orders">Orders</a>
        </nav>
    </div>';

    
    //Home Page
    if (isset($_GET['page']) && $_GET['page'] === 'home') {
        echo '<div class="home-page">';
        
        // Embed video
        echo '<div>';
        echo '<video width="100%" height="100%" controls autoplay loop>';
        echo '<source src="/datablitz/video.mp4" type="video/mp4">';
        echo 'Your browser does not support the video tag.';
        echo '</video>';
        echo '</div>';

        echo '</div>';
    }

    // Profile Page Content
if (isset($_GET['page']) && $_GET['page'] === 'profile') {

    echo '<style>
    /* Profile Page */
        .profile-page {
            padding: 40px;
            background-color:rgb(255, 255, 255);
            font-family: "Roboto", sans-serif;
            width: 100%;
        }

        /* Status Filter Buttons */
        .status-filter-buttons {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
        }

        .status-filter-buttons form {
            display: flex;
            gap: 20px;
        }

        .status-filter-buttons button {
            padding: 12px 18px;
            background-color: #0D3B66;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            transition: background-color 0.3s ease, transform 0.3s ease;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .status-filter-buttons button:hover {
            background-color:rgb(6, 91, 182);
            box-shadow: 0 6px 10px rgba(0, 0, 0, 0.15);
        }

        .status-filter-buttons button:active {
            transform: translateY(0);
        }

        /* Orders Table */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 30px;
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        table th, table td {
            padding: 15px 20px;
            text-align: left;
            font-size: 15px;
            color: #333;
            border-bottom: 1px solid #ddd;
        }

        table th {
            background-color:rgb(255, 255, 255);
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        table tbody tr:hover {
            background-color:rgb(255, 255, 255);
            cursor: pointer;
        }

        /* Status Column Styling */
        .stat-item {
            padding: 8px 15px;
            border-radius: 30px;
            color: white;
            font-weight: bold;
            text-transform: capitalize;
            font-size: 12px;
            display: inline-block;
            min-width: 80px;
            text-align: center;
        }

        .stat-item.pending {
            background-color: #FF8C00;
        }

        .stat-item.shipped {
            background-color: #007BFF;
        }

        .stat-item.cancelled {
            background-color: #FF4F4F;
        }

        .stat-item.delivered {
            background-color: #28A745;
        }

        /* Action Buttons */
        .action-buttons a {
            padding: 8px 15px;
            background-color: #28A745;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            transition: background-color 0.3s ease, transform 0.3s ease;
            margin-right: 10px;
        }

        .action-buttons a:hover {
            background-color: #218838;
            transform: translateY(-2px);
        }

        .action-buttons a:active {
            transform: translateY(0);
        }

        /* Profile Header */
        .profile-header {
            display: flex;
            align-items: center;
            margin-bottom: 30px;
        }

        .profile-header img {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            margin-right: 20px;
        }

        .profile-header h2 {
            font-size: 2em;
            color: #333;
        }

    </style>';


    echo '<div class="profile-page">';
    echo '<h1>My Purchases</h1>';
    // Add the buttons for filtering order status
    echo '<div class="status-filter-buttons">';
    echo '<form method="GET" action="">';
    echo '<input type="hidden" name="page" value="profile">'; // Keep the page parameter intact
    echo '<button type="submit" name="statusFilter" value="Pending">Pending</button>';
    echo '<button type="submit" name="statusFilter" value="Shipped">Shipped</button>';
    echo '<button type="submit" name="statusFilter" value="Cancelled">Cancelled</button>';
    echo '<button type="submit" name="statusFilter" value="Delivered">Delivered</button>';
    echo '</form>';
    echo '</div>';

    // Get the filter status if set
    $statusFilter = isset($_GET['statusFilter']) ? $_GET['statusFilter'] : '';

    // Fetch user-specific orders with optional status filter
    $userId = 1;  // Since the user's ID is 1
    $query = "SELECT * FROM orders WHERE user_Id = :userId";
    if ($statusFilter) {
        $query .= " AND order_Status = :statusFilter";
    }
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
    if ($statusFilter) {
        $stmt->bindParam(':statusFilter', $statusFilter, PDO::PARAM_STR);
    }
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Display Orders
    
    echo '<table>';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Order ID</th>';
    echo '<th>Customer Name</th>';
    echo '<th>Address</th>';
    echo '<th>Quantity</th>';
    echo '<th>Date</th>';
    echo '<th>Total</th>';
    echo '<th>Status</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    foreach ($orders as $order) {
        // Assign a class to each status for styling
        $statusClass = '';
        switch ($order['order_Status']) {
            case 'Pending':
                $statusClass = 'pending';
                break;
            case 'Shipped':
                $statusClass = 'shipped';
                break;
            case 'Cancelled':
                $statusClass = 'cancelled';
                break;
            case 'Delivered':
                $statusClass = 'delivered';
                break;
        }

        echo "<tr>
                <td>{$order['order_ID']}</td>
                <td>{$order['order_CustName']}</td>
                <td>{$order['order_Address']}</td>
                <td>{$order['order_Quantity']}</td>
                <td>{$order['order_Date']}</td>
                <td>₱{$order['order_Total']}</td>
                <td><span class='stat-item $statusClass'>{$order['order_Status']}</span></td>
              </tr>";
    }

    echo '</tbody>';
    echo '</table>';

    echo '</div>';
}



    //Peripherals Page
    if (isset($_GET['page']) && $_GET['page'] === 'peripherals') {
        echo '<form method="GET" action="index.php">
            <input type="hidden" name="page" value="peripherals">
           
            <div class="filter-box">
                <h3>FILTERS</h3>
                <h3>Category</h3>
                <div>
                    <label><input type="checkbox" name="category[]" value="Smartphones" ' . 
                        (isset($_GET["category"]) && in_array("Smartphones", $_GET["category"]) ? "checked" : "") . '> Smartphones</label><br>
                    <label><input type="checkbox" name="category[]" value="Gaming Peripherals" ' . 
                        (isset($_GET["category"]) && in_array("Gaming Peripherals", $_GET["category"]) ? "checked" : "") . '> Gaming Peripherals</label><br>
                    <label><input type="checkbox" name="category[]" value="Laptops & Tablets" ' . 
                        (isset($_GET["category"]) && in_array("Laptops & Tablets", $_GET["category"]) ? "checked" : "") . '> Laptops & Tablets</label><br>
                    <label><input type="checkbox" name="category[]" value="Headphones & Speakers" ' . 
                        (isset($_GET["category"]) && in_array("Headphones & Speakers", $_GET["category"]) ? "checked" : "") . '> Headphones & Speakers</label><br>
                </div>

                <h3>Price Range</h3>
                <input type="number" name="price_min" id="price_min" min="1" max="10000" value="' . 
                    (isset($_GET["price_min"]) ? htmlspecialchars($_GET["price_min"]) : "1") . '" placeholder="Min Price">
                <input type="number" name="price_max" id="price_max" min="1" max="10000" value="' . 
                    (isset($_GET["price_max"]) ? htmlspecialchars($_GET["price_max"]) : "10000") . '" placeholder="Max Price"><br>
                
                <h3>Rating</h3>
                <label><input type="checkbox" name="rating[]" value="1" ' . 
                    (isset($_GET["rating"]) && in_array("1", $_GET["rating"]) ? "checked" : "") . '> 1 Star</label><br>
                <label><input type="checkbox" name="rating[]" value="2" ' . 
                    (isset($_GET["rating"]) && in_array("2", $_GET["rating"]) ? "checked" : "") . '> 2 Stars</label><br>
                <label><input type="checkbox" name="rating[]" value="3" ' . 
                    (isset($_GET["rating"]) && in_array("3", $_GET["rating"]) ? "checked" : "") . '> 3 Stars</label><br>
                <label><input type="checkbox" name="rating[]" value="4" ' . 
                    (isset($_GET["rating"]) && in_array("4", $_GET["rating"]) ? "checked" : "") . '> 4 Stars</label><br>
                <label><input type="checkbox" name="rating[]" value="5" ' . 
                    (isset($_GET["rating"]) && in_array("5", $_GET["rating"]) ? "checked" : "") . '> 5 Stars</label><br>
                
                <div class="filter-buttons">
                    <button type="submit">Apply Filters</button>
                    <a href="index.php?page=peripherals" class="clear-filters">Clear</a>
                </div>
            </div>
        </form>';

        $whereClauses = ["prod_Category = 'Peripherals'"];
        $params = [];

        // If there's a search query, add it to the WHERE clause
        if (isset($_GET['search']) && !empty($_GET['search'])) {
            $whereClauses[] = "prod_Name LIKE ?";
            $params[] = "%" . $_GET['search'] . "%"; // Wildcard search for product names
        }

        // Category filter
        if (!empty($_GET['category']) && is_array($_GET['category'])) {
            $placeholders = implode(',', array_fill(0, count($_GET['category']), '?'));
            $whereClauses[] = "prod_SubCategory IN ($placeholders)";
            $params = array_merge($params, $_GET['category']);
        }

        // Price filter
        if (!empty($_GET['price_min']) && !empty($_GET['price_max'])) {
            $whereClauses[] = "prod_Price BETWEEN ? AND ?";
            $params[] = (int)$_GET['price_min'];
            $params[] = (int)$_GET['price_max'];
        }

        // Rating filter
        if (!empty($_GET['rating']) && is_array($_GET['rating'])) {
            $placeholders = implode(',', array_fill(0, count($_GET['rating']), '?'));
            $whereClauses[] = "prod_AvgRating IN ($placeholders)";
            $params = array_merge($params, $_GET['rating']);
        }

        $query = "SELECT * FROM product WHERE " . implode(' AND ', $whereClauses);
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo '<div class="product-container">';
        echo '<h1>Products</h1>';
        echo '<div class="product-grid">';
        if (!empty($products)) {
            foreach ($products as $product) {
                
                $rating = htmlspecialchars($product['prod_AvgRating']);
                
                if ($rating >= 1 && $rating < 2) {
                    $starImage = '1star.png';
                } elseif ($rating >= 2 && $rating < 3) {
                    $starImage = '2star.png';
                } elseif ($rating >= 3 && $rating < 4) {
                    $starImage = '3star.png';
                } elseif ($rating >= 4 && $rating < 5) {
                    $starImage = '4star.png';
                } elseif ($rating == 5) {
                    $starImage = '5star.png';
                } else {
                    $starImage = 'no-rating.png'; 
                }
        
                echo '<div class="product-box">
                    <a href="index.php?page=order_detail&product_id=' . htmlspecialchars($product['prod_Id']) . '" class="custom-link">
                    <img src="' . htmlspecialchars($product['prod_Image']) . '" alt="' . htmlspecialchars($product['prod_Name']) . '" class="product-image">
                    <h3>₱' . htmlspecialchars($product['prod_Price']) . '</h3>
                    <h4>' . htmlspecialchars($product['prod_Name']) . '</h4>
                    
                    <p>
                        <img src="/datablitz/' . $starImage . '" alt="' . $rating . '" class="product-rating">
                    </p>
                </div>';
            }
        } else {
            echo '<p>No products found matching the selected filters.</p>';
        }
        echo '</div>';
        echo '</div>';
    }   

    //Collectibles Page
    if (isset($_GET['page']) && $_GET['page'] === 'collectibles') {
        echo '<form method="GET" action="index.php">
            <input type="hidden" name="page" value="collectibles">
            <div class="filter-box">
                <h3>FILTERS</h3>
                <h3>Category</h3>
                <div>
                    <label><input type="checkbox" name="category[]" value="Action Figures" ' . 
                        (isset($_GET["category"]) && in_array("Action Figures", $_GET["category"]) ? "checked" : "") . '> Action Figures</label><br>
                    <label><input type="checkbox" name="category[]" value="Trading Cards" ' . 
                        (isset($_GET["category"]) && in_array("Trading Cards", $_GET["category"]) ? "checked" : "") . '> Trading Cards</label><br>
                    <label><input type="checkbox" name="category[]" value="Badge" ' . 
                        (isset($_GET["category"]) && in_array("Badge", $_GET["category"]) ? "checked" : "") . '> Badge</label><br>
                    <label><input type="checkbox" name="category[]" value="Funko Pop" ' . 
                        (isset($_GET["category"]) && in_array("Funko Pop", $_GET["category"]) ? "checked" : "") . '> Funko Pop</label><br>
                </div>

                <h3>Price Range</h3>
                <input type="number" name="price_min" id="price_min" min="1" max="10000" value="' . 
                    (isset($_GET["price_min"]) ? htmlspecialchars($_GET["price_min"]) : "1") . '" placeholder="Min Price">
                <input type="number" name="price_max" id="price_max" min="1" max="10000" value="' . 
                    (isset($_GET["price_max"]) ? htmlspecialchars($_GET["price_max"]) : "10000") . '" placeholder="Max Price"><br>
                
                <h3>Rating</h3>
                <label><input type="checkbox" name="rating[]" value="1" ' . 
                    (isset($_GET["rating"]) && in_array("1", $_GET["rating"]) ? "checked" : "") . '> 1 Star</label><br>
                <label><input type="checkbox" name="rating[]" value="2" ' . 
                    (isset($_GET["rating"]) && in_array("2", $_GET["rating"]) ? "checked" : "") . '> 2 Stars</label><br>
                <label><input type="checkbox" name="rating[]" value="3" ' . 
                    (isset($_GET["rating"]) && in_array("3", $_GET["rating"]) ? "checked" : "") . '> 3 Stars</label><br>
                <label><input type="checkbox" name="rating[]" value="4" ' . 
                    (isset($_GET["rating"]) && in_array("4", $_GET["rating"]) ? "checked" : "") . '> 4 Stars</label><br>
                <label><input type="checkbox" name="rating[]" value="5" ' . 
                    (isset($_GET["rating"]) && in_array("5", $_GET["rating"]) ? "checked" : "") . '> 5 Stars</label><br>
                
                <div class="filter-buttons">
                    <button type="submit">Apply Filters</button>
                    <a href="index.php?page=collectibles" class="clear-filters">Clear</a>
                </div>
            </div>
        </form>';

        $whereClauses = ["prod_Category = 'Collectibles'"];
        $params = [];

        // Category filter
        if (!empty($_GET['category']) && is_array($_GET['category'])) {
            $placeholders = implode(',', array_fill(0, count($_GET['category']), '?'));
            $whereClauses[] = "prod_SubCategory IN ($placeholders)";
            $params = array_merge($params, $_GET['category']);
        }

        // Price filter
        if (!empty($_GET['price_min']) && !empty($_GET['price_max'])) {
            $whereClauses[] = "prod_Price BETWEEN ? AND ?";
            $params[] = (int)$_GET['price_min'];
            $params[] = (int)$_GET['price_max'];
        }

        // Rating filter
        if (!empty($_GET['rating']) && is_array($_GET['rating'])) {
            $placeholders = implode(',', array_fill(0, count($_GET['rating']), '?'));
            $whereClauses[] = "prod_AvgRating IN ($placeholders)";
            $params = array_merge($params, $_GET['rating']);
        }

        $query = "SELECT * FROM product WHERE " . implode(' AND ', $whereClauses);
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo '<div class="product-container">';
        echo '<h1>Products</h1>';
        echo '<div class="product-grid">';
        if (!empty($products)) {
            foreach ($products as $product) {
                
                $rating = htmlspecialchars($product['prod_AvgRating']);
                
                if ($rating >= 1 && $rating < 2) {
                    $starImage = '1star.png';
                } elseif ($rating >= 2 && $rating < 3) {
                    $starImage = '2star.png';
                } elseif ($rating >= 3 && $rating < 4) {
                    $starImage = '3star.png';
                } elseif ($rating >= 4 && $rating < 5) {
                    $starImage = '4star.png';
                } elseif ($rating == 5) {
                    $starImage = '5star.png';
                } else {
                    $starImage = 'no-rating.png'; 
                }
        
                echo '<div class="product-box">
                    <a href="index.php?page=order_detail&product_id=' . htmlspecialchars($product['prod_Id']) . '" class="custom-link">
                    <img src="' . htmlspecialchars($product['prod_Image']) . '" alt="' . htmlspecialchars($product['prod_Name']) . '" class="product-image">
                    <h3>₱' . htmlspecialchars($product['prod_Price']) . '</h3>
                    <h4>' . htmlspecialchars($product['prod_Name']) . '</h4>
                    
                    <p>
                        <img src="/datablitz/' . $starImage . '" alt="' . $rating . '" class="product-rating">
                    </p>
                </div>';
            }
        } else {
            echo '<p>No products found matching the selected filters.</p>';
        }
        echo '</div>';
        echo '</div>';
    }

     //Games Page
     if (isset($_GET['page']) && $_GET['page'] === 'games') {
        echo '<form method="GET" action="index.php">
            <input type="hidden" name="page" value="games">
            <div class="filter-box">
                <h3>FILTERS</h3>
                <h3>Category</h3>
                <div>
                    <label><input type="checkbox" name="category[]" value="Xbox" ' . 
                        (isset($_GET["category"]) && in_array("Xbox", $_GET["category"]) ? "checked" : "") . '> Xbox</label><br>
                    <label><input type="checkbox" name="category[]" value="PS5" ' . 
                        (isset($_GET["category"]) && in_array("PS5", $_GET["category"]) ? "checked" : "") . '> PS5</label><br>
                    <label><input type="checkbox" name="category[]" value="PC/Android" ' . 
                        (isset($_GET["category"]) && in_array("PC/Android", $_GET["category"]) ? "checked" : "") . '> PC/Android</label><br>
                    <label><input type="checkbox" name="category[]" value="Nintendo Switch" ' . 
                        (isset($_GET["category"]) && in_array("Nintendo Switch", $_GET["category"]) ? "checked" : "") . '> Nintendo Switch</label><br>
                </div>

                <h3>Price Range</h3>
                <input type="number" name="price_min" id="price_min" min="1" max="10000" value="' . 
                    (isset($_GET["price_min"]) ? htmlspecialchars($_GET["price_min"]) : "1") . '" placeholder="Min Price">
                <input type="number" name="price_max" id="price_max" min="1" max="10000" value="' . 
                    (isset($_GET["price_max"]) ? htmlspecialchars($_GET["price_max"]) : "10000") . '" placeholder="Max Price"><br>
                
                <h3>Rating</h3>
                <label><input type="checkbox" name="rating[]" value="1" ' . 
                    (isset($_GET["rating"]) && in_array("1", $_GET["rating"]) ? "checked" : "") . '> 1 Star</label><br>
                <label><input type="checkbox" name="rating[]" value="2" ' . 
                    (isset($_GET["rating"]) && in_array("2", $_GET["rating"]) ? "checked" : "") . '> 2 Stars</label><br>
                <label><input type="checkbox" name="rating[]" value="3" ' . 
                    (isset($_GET["rating"]) && in_array("3", $_GET["rating"]) ? "checked" : "") . '> 3 Stars</label><br>
                <label><input type="checkbox" name="rating[]" value="4" ' . 
                    (isset($_GET["rating"]) && in_array("4", $_GET["rating"]) ? "checked" : "") . '> 4 Stars</label><br>
                <label><input type="checkbox" name="rating[]" value="5" ' . 
                    (isset($_GET["rating"]) && in_array("5", $_GET["rating"]) ? "checked" : "") . '> 5 Stars</label><br>
                
                <div class="filter-buttons">
                    <button type="submit">Apply Filters</button>
                    <a href="index.php?page=games" class="clear-filters">Clear</a>
                </div>
            </div>
        </form>';

        $whereClauses = ["prod_Category = 'Games'"];
        $params = [];

        // Category filter
        if (!empty($_GET['category']) && is_array($_GET['category'])) {
            $placeholders = implode(',', array_fill(0, count($_GET['category']), '?'));
            $whereClauses[] = "prod_SubCategory IN ($placeholders)";
            $params = array_merge($params, $_GET['category']);
        }

        // Price filter
        if (!empty($_GET['price_min']) && !empty($_GET['price_max'])) {
            $whereClauses[] = "prod_Price BETWEEN ? AND ?";
            $params[] = (int)$_GET['price_min'];
            $params[] = (int)$_GET['price_max'];
        }

        // Rating filter
        if (!empty($_GET['rating']) && is_array($_GET['rating'])) {
            $placeholders = implode(',', array_fill(0, count($_GET['rating']), '?'));
            $whereClauses[] = "prod_AvgRating IN ($placeholders)";
            $params = array_merge($params, $_GET['rating']);
        }

        $query = "SELECT * FROM product WHERE " . implode(' AND ', $whereClauses);
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo '<div class="product-container">';
        echo '<h1>Products</h1>';
        echo '<div class="product-grid">';
        if (!empty($products)) {
            foreach ($products as $product) {
                
                $rating = htmlspecialchars($product['prod_AvgRating']);
                
                if ($rating >= 1 && $rating < 2) {
                    $starImage = '1star.png';
                } elseif ($rating >= 2 && $rating < 3) {
                    $starImage = '2star.png';
                } elseif ($rating >= 3 && $rating < 4) {
                    $starImage = '3star.png';
                } elseif ($rating >= 4 && $rating < 5) {
                    $starImage = '4star.png';
                } elseif ($rating == 5) {
                    $starImage = '5star.png';
                } else {
                    $starImage = 'no-rating.png'; 
                }
        
                echo '<div class="product-box">
                    <a href="index.php?page=order_detail&product_id=' . htmlspecialchars($product['prod_Id']) . '" class="custom-link">
                    <img src="' . htmlspecialchars($product['prod_Image']) . '" alt="' . htmlspecialchars($product['prod_Name']) . '" class="product-image">
                    <h3>₱' . htmlspecialchars($product['prod_Price']) . '</h3>
                    <h4>' . htmlspecialchars($product['prod_Name']) . '</h4>
                    
                    <p>
                        <img src="/datablitz/' . $starImage . '" alt="' . $rating . '" class="product-rating">
                    </p>
                </div>';
            }
        } else {
            echo '<p>No products found matching the selected filters.</p>';
        }
        echo '</div>';
        echo '</div>';
    }

    // Product Order Details
    if (isset($_GET['page']) && $_GET['page'] === 'order_detail' && isset($_GET['product_id'])) {
        $productId = (int)$_GET['product_id']; // Cast to integer for security

        // Fetch product details from the database
        $query = "SELECT * FROM product WHERE prod_Id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($product) {
            $rating = htmlspecialchars($product['prod_AvgRating']);

            // Rating image selection
            $starImage = 'no-rating.png';
            if ($rating >= 1 && $rating < 2) $starImage = '1star.png';
            elseif ($rating >= 2 && $rating < 3) $starImage = '2star.png';
            elseif ($rating >= 3 && $rating < 4) $starImage = '3star.png';
            elseif ($rating >= 4 && $rating < 5) $starImage = '4star.png';
            elseif ($rating == 5) $starImage = '5star.png';

            // Stock status logic
            $stockStatus = $product['prod_StockStatus']; // "in-stock" or "out-of-stock"
            $isInStock = ($stockStatus === 'in-stock');
            $stockStatusText = $isInStock ? 'In Stock' : 'Out of Stock';
            $stockStatusColor = $isInStock ? 'green' : 'red';

            echo '<div class="product-detail-container">
            <!-- Left Box: Product Image -->
            <div class="product-image-box">
                <img src="' . htmlspecialchars($product['prod_Image']) . '" alt="' . htmlspecialchars($product['prod_Name']) . '">
            </div>

            <!-- Right Box: Product Details -->
            <div class="product-details-box">
                <h1>' . htmlspecialchars($product['prod_Name']) . '</h1>
                <p><strong>Price:</strong> ₱' . htmlspecialchars($product['prod_Price']) . '</p>
                <p><strong>Description:</strong> ' . htmlspecialchars($product['prod_Desc']) . '</p>
                <p><strong>Category:</strong> ' . htmlspecialchars($product['prod_Category']) . '</p>
                <p><strong>Subcategory:</strong> ' . htmlspecialchars($product['prod_SubCategory']) . '</p>
                <p><strong>Rating:</strong>
                    <img src="/datablitz/' . $starImage . '" alt="' . htmlspecialchars($rating) . '" class="product-rating">
                </p>

                <!-- Stock Status with Circle -->
                <span style="color: ' . $stockStatusColor . ';"><strong>Status:</strong> ' . $stockStatusText . '</span>

                <!-- Quantity Selector -->
                <form method="POST" action="index.php?page=orders">
                    <input type="hidden" name="product_id" value="' . htmlspecialchars($product['prod_Id']) . '">
                    
                    <label for="quantity"><strong>Quantity:</strong></label>
                    <input 
                        type="number" 
                        id="quantity" 
                        name="quantity" 
                        min="1" 
                        max="' . htmlspecialchars($product['prod_Quantity']) . '"  
                        value="1" 
                        ' . (!$isInStock ? 'disabled' : '') . '
                    >

                    <!-- Action Buttons -->
                    <div class="action-buttons">
                        <button 
                            type="submit" 
                            class="add-to-cart-button" 
                            ' . (!$isInStock ? 'style="pointer-events: none; opacity: 0.5;"' : '') . '
                        >
                            Add to Cart
                        </button>
                        <button 
                            type="button" 
                            class="cancel" 
                            onclick="window.location.href=\'index.php?page=home\'"
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>';

        }
    }
    
    // Handle Add to Cart Action
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['product_id'], $_POST['quantity'])) {
        $productId = (int)$_POST['product_id'];
        $quantity = (int)$_POST['quantity'];
        $userId = $_SESSION['user_Id'];

        // Fetch product details from the database
        $query = "SELECT prod_Price, prod_Quantity FROM product WHERE prod_Id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($product) {
            $price = $product['prod_Price'];
            $availableStock = $product['prod_Quantity'];

            // Check if the requested quantity exceeds available stock
            if ($quantity > $availableStock) {
                echo "Requested quantity exceeds available stock.";
                exit;
            }

            $totalPrice = $price * $quantity;

            // Check if the product is already in the cart
            $cartQuery = "SELECT * FROM cart_items WHERE user_Id = ? AND product_Id = ?";
            $cartStmt = $pdo->prepare($cartQuery);
            $cartStmt->execute([$userId, $productId]);
            $cartItem = $cartStmt->fetch(PDO::FETCH_ASSOC);

            if ($cartItem) {
                // Update quantity and total price
                $updateQuery = "UPDATE cart_items SET quantity = quantity + ?, total_price = total_price + ? WHERE user_Id = ? AND product_Id = ?";
                $updateStmt = $pdo->prepare($updateQuery);
                $updateStmt->execute([$quantity, $totalPrice, $userId, $productId]);
            } else {
                // Insert new cart item
                $insertQuery = "INSERT INTO cart_items (user_Id, product_Id, quantity, price, total_price) VALUES (?, ?, ?, ?, ?)";
                $insertStmt = $pdo->prepare($insertQuery);
                $insertStmt->execute([$userId, $productId, $quantity, $price, $totalPrice]);
            }

            // Deduct quantity from stock
            $remainingStock = $availableStock - $quantity;
            $deductStockQuery = "UPDATE product SET prod_Quantity = ?, prod_StockStatus = ? WHERE prod_Id = ?";
            $stockStatus = $remainingStock > 0 ? 'in-stock' : 'out-of-stock';
            $deductStockStmt = $pdo->prepare($deductStockQuery);
            $deductStockStmt->execute([$remainingStock, $stockStatus, $productId]);

            // Redirect to orders page
            header("Location: index.php?page=orders");
            exit;
        }
    }

   // Orders Page: Fetch cart items for logged-in user
    if (isset($_GET['page']) && $_GET['page'] === 'orders' && isset($_SESSION['user_Id'])) {
        $userId = $_SESSION['user_Id'];  // Get logged-in user ID

        // Fetch all cart items for the user that are "not-yet-checked-out"
        $query = "SELECT ci.cart_id, p.prod_Name, p.prod_Image, ci.quantity, p.prod_Price, 
                        (ci.quantity * p.prod_Price) AS total_price
                FROM cart_items ci
                JOIN product p ON ci.product_id = p.prod_ID
                WHERE ci.user_id = ? AND ci.cart_Status = 'not-yet-checked-out'";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$userId]);
        $cartItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo '<div class="orders-page-container">';

        if ($cartItems) {
            $totalPrice = 0;

            // Left Box: Cart Items
            echo '<div class="cart-items">';
            echo '<h2>Your Cart</h2>';

            // Add Header Row
            echo '<div class="cart-grid">';
            echo '<div class="cart-row" style="background-color:rgb(223, 239, 255); color: black; font-weight: bold; text-align: left; padding: 10px;">';
            echo '<div class="cart-column product-info" style="width: 40%; text-align: left;">Product</div>';
            echo '<div class="cart-column" style="width: 10%; text-align: left;">Price</div>';
            echo '<div class="cart-column" style="width: 20%; text-align: left;">Quantity</div>';
            echo '<div class="cart-column" style="width: 20%; text-align: left;">Subtotal</div>';
            echo '</div>'; // Close header row

            foreach ($cartItems as $cartItem) {
                $totalPrice += $cartItem['total_price'];
                echo '<div class="cart-row">';
                echo '<div class="cart-column product-info">';
                echo '<img src="' . htmlspecialchars($cartItem['prod_Image']) . '" alt="' . htmlspecialchars($cartItem['prod_Name']) . '" class="cart-item-image">';
                echo '<p>' . htmlspecialchars($cartItem['prod_Name']) . '</p>'; 
                echo '</div>';
                echo '<div class="cart-column">';
                echo '<p>₱' . htmlspecialchars($cartItem['prod_Price']) . '</p>';
                echo '</div>';
                echo '<div class="cart-column">';
                echo '<p>' . htmlspecialchars($cartItem['quantity']) . '</p>';
                echo '</div>';
                echo '<div class="cart-column">';
                echo '<p>₱' . htmlspecialchars($cartItem['total_price']) . '</p>';
                echo '</div>';
                echo '</div>'; // Close cart-row
            }
            echo '</div>'; // Close cart-grid
            echo '</div>'; // Close cart-items

            $shippingFee = 35;
            $grandTotal = $totalPrice + $shippingFee;
            
            echo '<div class="cart-summary">';
            echo '<h3>Order Summary</h3>';
            echo '<div style="display: table; width: 100%; margin-top: 20px;">';

            // Subtotal
            echo '<div style="display: table-row;">';
            echo '<div style="display: table-cell; text-align: left; padding: 5px 0; font-weight: normal;">Subtotal:</div>';
            echo '<div style="display: table-cell; text-align: right; padding: 5px 0;">₱' . number_format($totalPrice, 2) . '</div>';
            echo '</div>';

            // Shipping Fee
            echo '<div style="display: table-row;">';
            echo '<div style="display: table-cell; text-align: left; padding: 5px 0; font-weight: normal;">Shipping Fee:</div>';
            echo '<div style="display: table-cell; text-align: right; padding: 5px 0;">₱' . number_format($shippingFee, 2) . '</div>';
            echo '</div>';

            // Total Payment
            echo '<div style="display: table-row; font-weight: bold; font-size: 18px; color: #003366">';
            echo '<div style="display: table-cell; text-align: left; padding: 10px 0;">Total Payment:</div>';
            echo '<div style="display: table-cell; text-align: right; padding: 10px 0;">₱' . number_format($grandTotal, 2) . '</div>';
            echo '</div>';

            echo '</div>';
            
            echo '<div class="shipping-option">';
            echo '<h4>Shipping Option:</h4>';
            echo '<p style="display: flex; align-items: center; gap: 10px;">';
            echo '<strong>Standard Local</strong>';
            echo '<img src="/datablitz/delivery.png" alt="Standard Local" style="width: 20px; height: 20px; object-fit: contain;">'; // Replace with the correct image path
            echo '</p>';
            echo '<p>Guaranteed to get by 1-3 working days</p>';
            echo '<p><em>Get a ₱50 voucher if no delivery was attempted by 3 days.</em></p>';
            echo '</div>';
            
            // Checkout Button
            echo '<a href="index.php?page=checkout" class="checkout-button">Checkout</a>';
            echo '</div>'; // Close cart-summary
            
        } else {
            echo '<p>No items in your cart.</p>';
        }

        echo '</div>'; // Close orders-page-container
    } // Close order page logic

  // Checkout Page
 
  if (isset($_GET['page']) && $_GET['page'] === 'checkout' && isset($_SESSION['user_Id'])) {
      $userId = $_SESSION['user_Id'];
  
      // Fetch user information with concatenated name and address
      $userQuery = "SELECT 
                      CONCAT(user_Fname, ' ', user_Lname) AS full_name,
                      CONCAT(user_HouseNum, ', ', user_Brgy, ', ', user_Street, ', ', user_City) AS full_address,
                      user_ContactNum,
                      user_Card1,
                      user_Card2
                    FROM user 
                    WHERE user_Id = ?";
      $userStmt = $pdo->prepare($userQuery);
      $userStmt->execute([$userId]);
      $userInfo = $userStmt->fetch(PDO::FETCH_ASSOC);
  
      // Fetch cart items for checkout
      $cartQuery = "SELECT ci.cart_id, p.prod_Name, ci.quantity, p.prod_Price, (ci.quantity * p.prod_Price) AS total_price
                    FROM cart_items ci
                    JOIN product p ON ci.product_id = p.prod_ID
                    WHERE ci.user_id = ? AND ci.cart_Status = 'not-yet-checked-out'";
      $cartStmt = $pdo->prepare($cartQuery);
      $cartStmt->execute([$userId]);
      $cartItems = $cartStmt->fetchAll(PDO::FETCH_ASSOC);
  
      // Calculate totals
      $totalPrice = array_sum(array_column($cartItems, 'total_price'));
      $shippingFee = 35; // Fixed shipping fee
      $grandTotal = $totalPrice + $shippingFee;
  
      // Handle the payment processing after form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payment_method'])) {
        $paymentMethod = $_POST['payment_method'];

        // Display payment confirmation (Static Payment - No API integration)
        echo '<p>Payment method: ' . htmlspecialchars($paymentMethod) . '</p>';
        echo '<p>Order placed successfully! Thank you for your purchase.</p>';

        // Insert new order record into orders table
        $orderDate = date('Y-m-d');  // Use the current date for the order date
        $orderStatus = 'Pending';    // Set the initial order status to "Pending"
        $orderTotal = $grandTotal;   // Total amount for the order

        // Fetch cart item details for the user
        $cartQuery = "SELECT ci.cart_id, p.prod_Name, ci.quantity, p.prod_Price, (ci.quantity * p.prod_Price) AS total_price
                    FROM cart_items ci
                    JOIN product p ON ci.product_id = p.prod_ID
                    WHERE ci.user_Id = ? AND ci.cart_Status = 'not-yet-checked-out'";
        $cartStmt = $pdo->prepare($cartQuery);
        $cartStmt->execute([$userId]);
        $cartItems = $cartStmt->fetchAll(PDO::FETCH_ASSOC);

        // Prepare order details
        $orderCustName = htmlspecialchars($userInfo['full_name']);
        $orderAddress = htmlspecialchars($userInfo['full_address']);
        $orderQuantity = array_sum(array_column($cartItems, 'quantity'));  // Total quantity of all items

        // Insert order into orders table
        $insertOrderQuery = "INSERT INTO orders (order_CustName, order_Address, order_Quantity, order_Date, order_Total, order_Status, user_Id)
                            VALUES (?, ?, ?, ?, ?, ?, ?)";
        $insertOrderStmt = $pdo->prepare($insertOrderQuery);
        $insertOrderStmt->execute([$orderCustName, $orderAddress, $orderQuantity, $orderDate, $orderTotal, $orderStatus, $userId]);

        // Get the last inserted order ID (auto-generated)
        $orderId = $pdo->lastInsertId();


        // Optionally, update cart status to "checked-out"
        $updateCartStatusQuery = "UPDATE cart_items SET cart_Status = 'checked-out' WHERE user_id = ? AND cart_Status = 'not-yet-checked-out'";
        $updateCartStatusStmt = $pdo->prepare($updateCartStatusQuery);
        $updateCartStatusStmt->execute([$userId]);

        // Optionally, you can redirect the user or show a confirmation message
        // header("Location: confirmation.php");
        exit;
    }


  
      echo '<div class="checkout-page">
      <form action="index.php?page=checkout" method="POST">
          <!-- Box 1: User Info -->
          <div class="user-info">
              <h2>Shipping Information</h2>
              <div class="info-container">
                  <!-- Left side: Full Name and Contact Number -->
                  <div class="left-side">
                      <h1>' . htmlspecialchars($userInfo["full_name"]) . '</h1>
                      <p>' . htmlspecialchars($userInfo["user_ContactNum"]) . '</p>
                  </div>
  
                  <!-- Center: Address -->
                  <div class="center">
                      <h2 style="color: black;">' . htmlspecialchars($userInfo['full_address']) . '</h2>
                  </div>
  
                  <!-- Right side: Default Box -->
                  <div class="right-side">
                      <p>Default</p>
                  </div>
              </div>
          </div>
  
          <!-- Box 2: Products Ordered -->
          <div class="order-summary">
              <h2>Order Summary</h2>
              <div class="cart-grid">';
              foreach ($cartItems as $item) {
                  echo '<div class="cart-row">
                      <div class="cart-column">' . htmlspecialchars($item['prod_Name']) . '</div>
                      <div class="cart-column">₱' . number_format($item['prod_Price'], 2) . '</div>
                      <div class="cart-column">' . htmlspecialchars($item['quantity']) . '</div>
                      <div class="cart-column">₱' . number_format($item['total_price'], 2) . '</div>
                  </div>';
              }
              echo '</div>
          </div>
  
          <!-- Box 3: Payment Method -->
          <div class="payment-method">
              <h2>Payment Method</h2>
              
              <!-- Payment options with images and account numbers -->
              <div class="payment-options">
                  <div class="payment-option">
                      <div class="payment-method-img">
                          <img src="/datablitz/gcash.png" alt="Gcash" style="width: 50px; height: auto;"/>
                      </div>
                      <div class="payment-details">
                          <input type="radio" id="gcash" name="payment_method" value="gcash" required>
                          <p><strong>Account Number: </strong>' . htmlspecialchars($userInfo["user_Card1"]) . '</p>
                      </div>
                  </div>
                  
                  <div class="payment-option">
                      <div class="payment-method-img">
                          <img src="/datablitz/card.png" alt="Card" style="width: 50px; height: auto;"/>
                      </div>
                      <div class="payment-details">
                          <input type="radio" id="card" name="payment_method" value="card" required>
                          <p><strong>Account Number: </strong>' . htmlspecialchars($userInfo["user_Card2"]) . '</p>
                      </div>
                  </div>
              </div>
  
            <!-- Display total prices in a table -->
            <div class="total-prices">
                <table>
                    <tr>
                        <td><strong>Subtotal:</strong></td>
                        <td style="text-align: right;">₱' . number_format($totalPrice, 2) . '</td>
                    </tr>
                    <tr>
                        <td><strong>Shipping Fee:</strong></td>
                        <td style="text-align: right;">₱' . number_format($shippingFee, 2) . '</td>
                    </tr>
                    <tr>
                        <td><strong>Total Payment:</strong></td>
                        <td style="text-align: right;">₱' . number_format($grandTotal, 2) . '</td>
                    </tr>
                </table>
                <button class="place-order-button" type="submit">Place Order</button>
            </div>
              </div>
          </div>
          </form>
      </div>';
  
  
} // End of checkout page logic

        } //Close if else
            echo '</div>';
            echo '</body>';
            echo '</html>';
            exit;
    }    

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <style>
        /* General Reset */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        /* Body and Background Video */
        body {
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            font-family: 'Arial', sans-serif;
            overflow: hidden;
            position: relative;
        }

        /* Background Video */
        .background-video {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            z-index: -1; /* Keep the video behind other content */
        }

        /* Login Container */
        .login-container {
            background: rgba(0, 0, 0, 0.6); /* Semi-transparent background */
            padding: 40px 60px;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
            text-align: center;
            width: 100%;
            max-width: 400px;
            color: #fff;
            position: relative;
        }

        /* Heading */
        .login-container h1 {
            font-size: 36px;
            margin-bottom: 20px;
            color: #fff;
        }

        /* Form Labels */
        .login-container label {
            display: block;
            font-size: 16px;
            margin-bottom: 8px;
            color: #fff;
        }

        /* Form Inputs */
        .login-container input {
            width: 100%;
            padding: 12px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            background-color: rgba(255, 255, 255, 0.2);
            color: #fff;
            outline: none;
            transition: border 0.3s;
        }

        .login-container input:focus {
            border-color: #007BFF;
        }

        /* Submit Button */
        .login-container button {
            padding: 12px 20px;
            background-color: #007BFF;
            color: white;
            font-size: 16px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s ease;
            width: 100%;
        }

        .login-container button:hover {
            background-color: #0056b3;
        }

        /* Error Message */
        .error-message {
            color: red;
            font-size: 14px;
            margin-bottom: 20px;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .login-container {
                padding: 30px 40px;
            }

            .login-container h1 {
                font-size: 28px;
            }

            .login-container input, .login-container button {
                font-size: 14px;
            }
        }
    </style>
</head>
<body>

    <!-- Background Video -->
    <video class="background-video" autoplay muted loop>
        <source src="/datablitz/video.mp4" type="video/mp4">
        Your browser does not support the video tag.
    </video>

    <?php if (isset($_GET['page']) && $_GET['page'] === 'login'): ?>
        <div class="login-container">
            <h1>Login</h1>
            <?php if (!empty($error_message)): ?>
                <p class="error-message"><?= htmlspecialchars($error_message) ?></p>
            <?php endif; ?>
            <form method="POST" action="?page=login">
                <input type="email" name="email" id="email" required><br>
                <input type="password" name="password" id="password" required><br>

                <button type="submit">Login</button>
            </form>
        </div>
    <?php else: ?>
        <p>Please go to <a href="?page=login">Login Page</a></p>
    <?php endif; ?>

</body>
</html>




<?php

ob_end_flush();

?>