<?php
// var_dump($_POST); exit;
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'database.php';

// Frontend can expect JSON data
header("Content-Type: application/json");

// Determine HTTP method being used
$method = $_SERVER['REQUEST_METHOD'];

// If no action set, default to empty string
$action = $_REQUEST['action'] ?? '';

// Connect to legacy database
$legacy_db = getLegacyDB();

// GET HTTP requests
if ($method === 'GET')
{


    switch ($action)
    {
        case 'get_catalog':
            // Logic to get all parts for browsing catalog
            $stmt = $legacy_db->query("SELECT * FROM parts");
            $parts = $stmt->fetchAll();
            echo json_encode($parts);
            break;

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

            break;

        default:
            // If bad action sent
            echo json_encode(["error" => "Invalid action requested"]);
            break;
    }
}
elseif ($method === 'POST')
{


    switch ($action)
    {
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

            // Set cost, defaulting to 0 if weight exceeds your highest bracket
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

        case 'check_inventory':
            $part_number = intval($_POST['part_number']);

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
        case 'update_inventory':
            break;

        default:
            break;
    }
}
