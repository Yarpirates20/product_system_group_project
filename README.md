#### Using the API with HTML forms
---

##### POST methods
- Use the form action with: "api.php?_action_requested_"
- For when you need to write something to database
```
<form action="api.php?action=place_order" method="POST">
    <!-- Your input fields go here -->
    <input type="text" name="part_number">
    <button type="submit">Order</button>
</form>
```

##### GET methods
- When requesting info from system/database
- Use <input type="hidden" name="action" value="_your_action_"
```
<form action="api.php" method="GET">
    <!-- Hidden field routes the API -->
    <input type="hidden" name="action" value="get_part">
    
    <!-- Visible field for the user -->
    <input type="text" name="part_number">
    <button type="submit">Search</button>
</form>
```
API returns requested info or success/failure message as JSON
