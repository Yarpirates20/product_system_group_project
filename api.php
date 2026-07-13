<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'database.php';

// Frontend can expect JSON data
header("Content-Type: application/json");

// Determine HTTP method being used
$method = $_SERVER['REQUEST_METHOD'];

// If no action set, default to empty string
$action = $_GET['action'] ?? '';

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
            $address = $_POST['shipping_address'];
            $cc_number = $_POST['cc_number'];
            $cc_exp = $_POST['cc_exp'];

            $total_price = 0;

            // Loop through parts being ordered and call price from legacy DB and multiply by quantity before adding to total_price variable
            for ($i = 0; $i < count($part_numbers); $i++)
            {
                $current_part = $part_numbers[$i];
                $current_qty = $quantities[$i];

                // Ensure part number is integer
                $part_number = intval($current_part);

                // SELECT from legacy DB for a specific part
                $stmt = $legacy_db->prepare("SELECT price FROM parts WHERE number = ?");
                $stmt->execute([$part_number]);
                $part = $stmt->fetch();

                $subtotal = $current_qty * $part['price'];
                $total_price += $subtotal;
            }

            // echo $part_number . $qty . $cc_number;

            // Authorize card
            $url = 'http://blitz.cs.niu.edu/CreditCard/';

            $data = array(
                'vendor' => '1A',
                'trans' => uniqid(),
                'cc' => $cc_number,
                'name' => $name,
                'exp' => $cc_exp,
                'amouunt' => $total_price);

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

            $authorization_number = null;

            if (str_starts_with($result, 'Error'))
            {
                // The card was declined or there was an issue
                echo json_encode(["status" => "error", "message" => $result]);
                exit;
            }
            else
            {
                $authorization_number = trim($result);
                echo json_encode([
                    "status" => "success",
                    "message" => "Order placed successfully.",
                    "transaction_id" => $data['$trans'],
                    "authorization_number" => $result
                ]);
            }

            // Save transaction to database
            $local_db = getLocalDB();
            $stmt = $local_db->prepare('Insert INTO Orders (customer_name, shipping_address, total_price, transaction_id) VALUES (?, ?, ?, ?)');

            $stmt->execute([$name, $address, $total_price, $data['$trans']]);

            break;

        case 'update_inventory':
            break;

        default:
            break;
    }
}
