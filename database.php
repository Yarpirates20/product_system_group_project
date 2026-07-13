<?php

/**
 * Connection to Legacy Database 
 */
function getLegacyDB()
{
    $host = 'blitz.cs.niu.edu';
    $db = 'csci467';
    $user = 'student';
    $pass = 'student';


    $pdo = null;
    
    try
    {
        $dsn = "mysql:host=$host;dbname=$db";
        $pdo = new PDO($dsn, $user, $pass);
    }
    catch (PDOException $e)
    {
        echo "Connection to legacy database failed: " . $e->getMessage();
    }

    return $pdo;
}

/**
 * Connection to internal database.
 */
function getLocalDB()
{
    // UPDATE THESE TO GO TO ACTUAL DB
    $host = '127.0.0.1';
    $db = 'mariadb';
    $user = 'mariadb';
    $pass = 'mariadb';

    $pdo = null;

    try 
    {
        $dsn = "mysql:host=$host;dbname=$db";
        $pdo = new PDO($dsn, $user, $pass);
    } 
    catch (PDOException $e) 
    {
        echo "Connection to internal database failed: " . $e->getMessage();
    }

    return $pdo;
}

?>