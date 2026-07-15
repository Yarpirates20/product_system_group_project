<?php

/**
 * DEBUG:      REMOVE BEFORE SUBMISSION
 */
// var_dump($_POST); exit;
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

require_once 'database.php';

// Frontend can expect JSON data
header("Content-Type: application/json");

// Determine HTTP method being used
$method = $_SERVER['REQUEST_METHOD'];

// If no action set, default to empty string
$action = $_REQUEST['action'] ?? '';


// GET HTTP requests
if ($method === 'GET')
{

    // Connect to legacy database
    $legacy_db = getLegacyDB();

    switch ($action)
    {
        case 'get_catalog':
            // Logic to get all parts for browsing catalog
            $stmt = $legacy_db->query("SELECT * FROM parts");
            $parts = $stmt->fetchAll();
            echo json_encode($parts);
            exit;

        case 'get_part':
            // Logic for a single part
            // Trim any whitespace characters
            $raw_input = trim($_GET['part_number']);

            // Ensure part number is integer
            $part_number = intval($raw_input);

            // SELECT from legacy DB for a specific part
            $stmt = $legacy_db->prepare("SELECT * FROM parts WHERE number = ?");
            $stmt->execute([$part_number]);
            $part = $stmt->fetch();

            if ($part === false)
            {
                // Return error if no part found
                echo json_encode(["error" => "Part not found", "searched)_for" => $part_number]);
            }
            else
            {
                // Return part if found
                echo json_encode($part);
            }

            exit;

        /**
         * Query inventory
         */
        case 'check_inventory':
            $part_number = intval($_GET['part_number']);

            // Query the database
            $local_db = getLocalDB();
            $stmt = $local_db->prepare("SELECT quantity_on_hand FROM Inventory WHERE product_id = ?");
            $stmt->execute([$part_number]);
            $result = $stmt->fetch();

            if ($result)
            {
                echo json_encode([
                    "status" => "success",
                    "part" => $part_number,
                    "quantity" => $result['quantity_on_hand']
                ]);
            }
            else
            {
                echo json_encode([
                    "status" => "error",
                    "message" => "Part not found in inventory."
                ]);
            }

            exit;

        /**
         * Search for orders using date range, status, price range.
         */
        case 'search_orders':
            $params = [];

            $sql = "SELECT * FROM Orders WHERE 1=1";

            // Check for date criteria
            if (!empty($_GET['start_date']) && !empty('end_date'))
            {
                $sql .= " AND order_date BETWEEN ? AND ?";
                $params[] = $_GET['start_date'];
                $params[] = $_GET['end_date'];
            }

            // Check for status
            if (!empty($_GET['status']))
            {
                $sql .= " AND STATUS = ?";
                $params[] = $_GET['status'];
            }

            // Check for price range
            if (!empty($_GET['min_price'] && !empty($_GET['max_price'])))
            {
                $sql .= " AND total_price BETWEEN ? AND ?";
                $params[] = doubleval($_GET['min_price']);
                $params[] = doubleval($_GET['max_price']);
            }

            // Prepare & execute final query string
            $local_db = getLocalDB();
            $stmt = $local_db->prepare($sql);
            $stmt->execute($params);
            $orders = $stmt->fetchAll();

            // Return results
            echo json_encode([
                "status" => "success",
                "data" => $orders
            ]);

            exit;


        /**
         * Retrieve order details using order_id
         */
        case 'get_order_details':
            if (empty($_GET['order_id']))
            {
                echo json_encode([
                    "status" => "error",
                    "message" => "Missing order ID"
                ]);
            }

            $order_id = intval($_GET['order_id']);
            $local_db = getLocalDB();

            // Fetch order record
            $stmt_order = $local_db->prepare("SELECT * FROM Orders WHERE order_id = ?");
            $stmt_order->execute([$order_id]);
            $order = $stmt_order->fetch();

            if (!$order)
            {
                echo json_encode([
                    "status" => "error",
                    "message" => "Order not found"
                ]);
            }

            // Fetch associated line items for order
            $stmt_items = $local_db->prepare("SELECT * FROM OrderItem WHERE order_id = ?");
            $stmt_items->execute([$order_id]);
            $items = $stmt_items->fetchAll();

            // Combine results into JSON response
            echo json_encode([
                "status" => "success",
                "order_info" => $order,
                "items" => $items
            ]);


            exit;

        default:
            // If bad action sent
            echo json_encode(["error" => "Invalid action requested"]);
            exit;
    }
}
elseif ($method === 'POST')
{
    switch ($action)
    {
        /**
         * Processes shopping cart items by calculating their price based on price data from legacy databqase, 
         */
        case 'place_order':
            // Extract information from front end
            $part_numbers = $_POST['part_number'];  // array of parts
            $quantities = $_POST['quantity'];              // array of qty
            $name = $_POST['customer_name'];
            $email = $_POST['email'];
            $address = $_POST['shipping_address'];
            $cc_number = $_POST['cc_number'];
            $cc_exp = $_POST['cc_exp'];

            $total_price = 0;
            $total_weight = 0;

            // Connect to legacy database
            $legacy_db = getLegacyDB();

            // Loop through parts being ordered and call price from legacy DB and multiply by quantity before adding to total_price variable
            for ($i = 0; $i < count($part_numbers); $i++)
            {
                $current_part = $part_numbers[$i];
                $current_qty = $quantities[$i];

                // Ensure part number is integer
                $part_number = intval($current_part);

                // SELECT from legacy DB for a specific part
                $stmt = $legacy_db->prepare("SELECT price, weight FROM parts WHERE number = ?");
                $stmt->execute([$part_number]);
                $part = $stmt->fetch();

                $total_weight += ($current_qty * $part['weight']);

                $subtotal = $current_qty * $part['price'];
                $total_price += $subtotal;
            }

            $local_db = getLocalDB();

            // Determine shipping cost based on total weight
            $stmt = $local_db->prepare("SELECT cost FROM ShippingRate WHERE ? BETWEEN min_weight AND max_weight");
            $stmt->execute([$total_weight]);
            $shipping = $stmt->fetch();

            // Set cost, defaulting to 0 if weight exceeds highest bracket
            $shipping_cost = $shipping ? $shipping['cost'] : 0;

            // Add to grand total
            $total_price += $shipping_cost;

            // Authorize card
            $url = 'http://blitz.cs.niu.edu/CreditCard/';

            $data = array(
                'vendor' => '1A',
                'trans' => uniqid(),
                'cc' => $cc_number,
                'name' => $name,
                'exp' => $cc_exp,
                'amount' => $total_price
            );

            // $authorization_data['cc_number'] = $cc_number;
            // $authorization_data['cc_exp'] = $cc_exp;
            // $authorization_data['amount'] = $total_price;
            // $authorization_data['name'] = $name;
            // $authorization_data['vendor_id'] = '1A';
            // $authorization_data['transaction_id'] = uniqid();

            $options = array(
                'http' => array(
                    'header' => array('Content-type: application/json', 'Accept: application/json'),
                    'method' => 'POST',
                    'content' => json_encode($data)
                )
            );

            $context  = stream_context_create($options);
            $result = file_get_contents($url, false, $context);

            // Decode JSON string returned be credit card gateway to array
            $cc_response = json_decode($result, true);

            $authorization_number = null;

            if (isset($cc_response['errors']) && count($cc_response['errors']) > 0)
            {
                // The card was declined. Output the specific error and stop the script.
                echo json_encode([
                    "status" => "error",
                    "message" => "Credit card declined: " . implode(", ", $cc_response['errors'])
                ]);
                exit;
            }

            // If no errors, capture the authorization data and continue to database insertion
            $authorization_number = $result;

            echo json_encode([
                "status" => "success",
                "message" => "Order placed successfully.",
                "transaction_id" => $data['trans'],
                "authorization_number" => $result
            ]);

            // Save transaction to database

            // Check if the customer already exists using their email
            $stmt = $local_db->prepare("SELECT customer_id FROM Customer WHERE email = ?");
            $stmt->execute([$email]);
            $customer = $stmt->fetch();

            if ($customer)
            {
                // If they exist, extract their existing ID
                $customer_id = $customer['customer_id'];
            }
            else
            {
                // If they do not exist, insert the new record
                $stmt = $local_db->prepare("INSERT INTO Customer (name, email, address) VALUES (?, ?, ?)");
                $stmt->execute([$name, $email, $address]);

                // Grab the ID
                $customer_id = $local_db->lastInsertId();
            }


            // Insert Order into local DB & grab OrderID for OrderItem
            $stmt = $local_db->prepare('Insert INTO Orders (customer_id, status, total_price, shipping_cost) VALUES (?, ?, ?, ?)');

            $stmt->execute([$customer_id, 'Authorized', $total_price, $shipping_cost]);

            $order_id = $local_db->lastInsertId();

            // Prepare OrderItem statements 
            $stmt_item = $local_db->prepare("INSERT INTO OrderItem (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");

            $stmt_inventory = $local_db->prepare("UPDATE Inventory SET quantity_on_hand = quantity_on_hand - ? WHERE product_id = ?");

            $stmt_legacy = $legacy_db->prepare("SELECT price FROM parts WHERE number = ?");

            for ($i = 0; $i < count($part_numbers); $i++)
            {
                $product_id = intval($part_numbers[$i]);
                $qty = intval($quantities[$i]);

                // Fetch the price for this specific item again
                $stmt_legacy->execute([$product_id]);
                $price = $stmt_legacy->fetchColumn();

                // Create the Line Item
                $stmt_item->execute([$order_id, $product_id, $qty, $price]);

                // Deduct the Inventory
                $stmt_inventory->execute([$qty, $product_id]);
            }

            exit;


        /**
         * 
         */
        case 'update_inventory':

            // Get data from front end
            $product_id = intval($_POST['product_id']);
            $received_quantity = intval($_POST['quantity']);

            $local_db = getLocalDB();

            $stmt = $local_db->prepare("UPDATE Inventory SET quantity_on_hand = quantity_on_hand + ? WHERE product_id = ?");
            $result = $stmt->execute([$received_quantity, $product_id]);

            if ($result && $stmt->rowCount() > 0)
            {
                echo json_encode([
                    "status" => "success",
                    "message" => "Inventory received and updated.",
                    "product_id" => $product_id,
                    "quantity_added" => $received_quantity
                ]);
            }
            else
            {
                echo json_encode([
                    "status" => "error",
                    "message" => "Failed to update inventory."
                ]);
            }
            exit;

        case 'update_status':
            $order_id = intval($_POST['order_id']);
            $new_status = $_POST['status'];

            $local_db = getLocalDB();
            $stmt = $local_db->prepare("UPDATE Orders SET status = ? WHERE order_id = ?");

            $result = $stmt->execute([$new_status, $order_id]);

            if ($result && $stmt->rowCount() > 0)
            {
                echo json_encode([
                    "status" => "success",
                    "message" => "Order status updated.",
                    "order_id" => $order_id,
                    "new_status" => $new_status
                ]);
            }
            else
            {
                echo json_encode([
                    "status" => "error",
                    "message" => "Failed to update order."
                ]);
            }
            exit;

        case 'set_shipping_rates':
            // Get front end data
            $new_cost = doubleval($_POST['rate']);
            $min_weight = doubleval($_POST['min_weight']);
            $max_weight = doubleval($_POST['max_weight']);



            $local_db = getLocalDB();

            // Check if exact bracket already exists
            $stmt_exact = $local_db->prepare("SELECT cost FROM ShippingRate WHERE min_weight = ? AND max_weight = ?");

            $stmt_exact->execute([$min_weight, $max_weight]);

            if ($stmt_exact->fetch())
            {
                $stmt = $local_db->prepare("UPDATE ShippingRATE SET cost = ? WHERE min_weight = ? AND max_weight = ?");

                $result = $stmt->execute([$new_cost, $min_weight, $max_weight]);

                echo json_encode([
                    "status" => "success",
                    "message" => "Shipping rates updated."
                ]);

                exit;
            }


            // If not EXACT match, check for ANY overlapping ranges
            $stmt_overlap = $local_db->prepare("SELECT rate_id FROM ShippingRate WHERE ? <= max_weight AND ? >= min_weight");

            $stmt_overlap->execute([$min_weight, $max_weight]);

            if ($stmt_overlap->fetch())
            {
                // Reject request due to overlapping weights
                echo json_encode([
                    "status" => "error",
                    "message" => "Bracket overlaps with an existing range. Please adjust or remove the conflicting bracket first."
                ]);
            }

            // No exact match or overlap --> insert new bracket
            $stmt_insert = $local_db->prepare("INSERT INTO ShippingRate (min_weight, max_weight, cost) VALUES (?, ?, ?)");

            $result = $stmt_insert->execute([$min_weight, $max_weight, $new_cost]);

            if ($result)
            {
                echo json_encode(["status" => "success", "message" => "New shipping bracket created."]);
            }
            else
            {
                echo json_encode(["status" => "error", "message" => "Failed to create new bracket."]);
            }


            exit;

        default:
            break;
    }
}
