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
            $part_number = $_POST['part_number'];
            $qty = $_POST['quantity'];
            $cc_number = $_POST['credit_card'];

            // echo $part_number . $qty . $cc_number;
            
            $total_price = 0;

            break;

        case 'update_inventory':
            break;

        default:
            break;
    }
}
