# osTicket to Kanboard Plugin

This plugin integrates osTicket with Kanboard by calling the Kanboard API when tickets are created, assigned, or closed.

## Features

- **Department-based Routing**: Map osTicket departments to specific Kanboard projects
- **Automatic Task Creation**: Creates Kanboard tasks when tickets are created via email or web
- **Username Mapping**: Automatically assigns tasks to Kanboard users with matching usernames
- **Status Synchronization**: Moves tasks to done column and closes them when tickets are resolved/closed
- **JSON-RPC API Integration**: Uses Kanboard's native JSON-RPC API
- **Duplicate Prevention**: Won't create duplicate tasks or process closed tickets

## Requirements

- osTicket v1.10.0 or later (tested on v1.18.2, but older versions should work)
- Kanboard instance with API access
- PHP with cURL support
- Matching usernames between osTicket staff and Kanboard users (for assignment feature)

## Installation

1. Copy the plugin files to your osTicket plugins directory:
   ```
   include/plugins/ostKanboard/
   ├── config.php
   ├── plugin.php
   └── webhook.php
   ```

2. Set appropriate file permissions so the web server can read the files:
   ```
   bash
   chmod 644 include/plugins/ostKanboard/*.php
   ```

3. Navigate to Admin Panel → Manage → Plugins

4. Click "Install" next to "osTicket Kanboard Plugin"

5. After installation, click on the plugin name to configure it

## Configuration

After installing the plugin, you need to enable it, then create and configure an instance:

### 1. Create Plugin Instance

1. Go to Admin Panel → Manage → Plugins
2. Click on "Kanboard Webhook Integration"
3. Click the "Instances" tab
4. Click "Add New Instance"
5. Give it a name (e.g., "Default") and set Status to "Active"

### 2. Configure API Settings

**Kanboard API URL**
Enter your Kanboard JSON-RPC API endpoint URL.

Example: `http://kanboard.domain.com/jsonrpc.php`

**Kanboard API Key**
Enter your Kanboard API key. You can generate this in Kanboard:
- Go to Settings → API
- Copy the API token and paste it here

The plugin will authenticate using username `jsonrpc` and your API key as the password.

### 3. Department to Project Mappings
Map osTicket department IDs to Kanboard project and column IDs. Each mapping should be on its own line in the format:

```
dept_id:project_id:new_column_id:done_column_id
```

**Example Configuration:**
```
1:1:2:1
2:3:2:5
5:1:2:1
```

This configuration means:
- Department 1 → Project 1, new tickets go to column 2, closed tickets to column 1
- Department 2 → Project 3, new tickets go to column 2, closed tickets to column 5
- Department 5 → Project 1, new tickets go to column 2, closed tickets to column 1

**Finding Department IDs:**
- Go to Admin Panel → Manage → Departments
- Click on a department and look at the URL: `?id=X` where X is the department ID

**Finding Kanboard Project and Column IDs:**
- In Kanboard, go to your project
- Look at the URL for the project ID
- For column IDs, use the Kanboard API: 
```
 curl -v http://kanboard.domain.com/jsonrpc.php -u "jsonrpc:{API Token}" -H "Content-Type: application/json" -d '{"jsonrpc":"2.0","method":"getColumns","id":1,"params":[1]}'
 ```
 The "params" value is the Project ID. This will return a JSON object of all columns in Project 1. you are looking for the value of "id".

### 3. Default Swimlane ID (Optional)
Set the default swimlane ID for tasks. Use `0` for the default swimlane.

## How It Works

### Event: Ticket Created
When a new ticket is created (via email or web) in a mapped department:
- Checks if a task already exists in Kanboard with this ticket number (prevents duplicates)
- Skips creation if ticket is already closed/resolved
- Calls `createTask` JSON-RPC method
- Creates a new task in the corresponding Kanboard project
- Places it in the configured "new" column
- Maps priority and description
- Task is created unassigned (assignment happens separately)

### Event: Ticket Assigned
When a ticket is assigned to a staff member:
- Searches for the task by reference (ticket number)
- Gets the staff member's username from osTicket
- Calls `getUserByName` to find the matching Kanboard user
- If user exists in Kanboard, calls `updateTask` to assign the task
- If user doesn't exist in Kanboard, assignment is skipped
- **Note**: Staff usernames in osTicket must match usernames in Kanboard

### Event: Ticket Closed/Resolved
When a ticket status is set to "Resolved" or "Closed":
- Searches for the task by reference (ticket number)
- If task exists, calls `moveTaskPosition` to move to "done" column
- Calls `closeTask` to mark as closed in Kanboard
- If task doesn't exist (e.g., old tickets), does nothing (won't create it)

## API Methods Used

The plugin uses the following Kanboard JSON-RPC API methods:
- `createTask` - Creates a new task
- `updateTask` - Updates task properties (used for assignment)
- `moveTaskPosition` - Moves a task to a different column
- `closeTask` - Closes a task
- `getAllTasks` - Retrieves all tasks from a project (used to find tasks by reference)
- `getUserByName` - Finds a Kanboard user by username (used for assignment)

## Important Notes

### User Assignment
- Assignment only works if the osTicket staff username matches a Kanboard username exactly
- If a matching user isn't found in Kanboard, the task remains unassigned
- Tasks are initially created unassigned, even if the ticket has an assignee

### Duplicate Prevention
- The plugin tracks tickets it has created to prevent duplicates
- Checks Kanboard for existing tasks before creating new ones
- Won't create tasks for tickets that are already closed/resolved

### Old Tickets
- If you close a ticket that was created before the plugin was installed, it won't create a task in Kanboard
- Only new tickets (created after plugin installation) will be synced

## Important Notes

- **Webhooks only fire for mapped departments**: If a department is not in your mapping configuration, no webhook will be sent
- **Error Logging**: Check your osTicket error logs for any webhook issues or unmapped department notifications
- **Priority Mapping**: 
  - Low (1) → Green, Priority 0
  - Normal (2) → Yellow, Priority 1
  - High (3) → Orange, Priority 2
  - Emergency (4) → Red, Priority 2

## Troubleshooting

### API calls not working
1. Verify the API URL is correct (should end with `/jsonrpc.php`)
2. Check that your API key is valid in Kanboard Settings → API
3. Check osTicket system logs (Admin Panel → Dashboard) for API error messages
4. Ensure your osTicket server can reach the Kanboard server
5. Test the API manually using curl:
```bash
curl -u "jsonrpc:YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"getVersion","id":1}' \
  http://kanboard.domain.com/jsonrpc.php
```

### Tasks not being created
1. Check that the department has a mapping configured in the plugin instance
2. Verify the project_id exists in Kanboard
3. Verify the column_id exists in that project
4. Check osTicket system logs for error messages
5. Ensure the plugin instance is enabled (Admin Panel → Manage → Plugins → Instances)

### Tasks not updating/closing
1. The plugin searches for tasks by reference (ticket number)
2. Ensure the ticket number format matches what's in Kanboard's reference field
3. Check that `getAllTasks` API method works for your Kanboard version
4. Look for "Could not find Kanboard task" messages in logs

### Assignment not working
1. Verify staff usernames in osTicket match usernames in Kanboard **exactly**
2. Test with `getUserByName` API call:
```bash
curl -u "jsonrpc:YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"getUserByName","id":1,"params":{"username":"staffusername"}}' \
  http://kanboard.domain.com/jsonrpc.php
```
3. If user is not found, create the user in Kanboard or adjust the username

### Duplicate tasks being created
1. This should not happen with the current version
2. If it does, check that the plugin is properly tracking created tickets
3. Verify that `getAllTasks` is returning tasks with the reference field populated

### Plugin not firing for email tickets
1. Ensure Email Fetching is enabled (Admin Panel → Emails → Settings)
2. Check that the cron job is running (auto-cron or external cron)
3. Plugin works with both IMAP polling and email piping
4. Check Apache/PHP error logs during email fetch

### Configuration not saving
1. Ensure you created a plugin instance (not just enabled the plugin)
2. Go to Admin Panel → Manage → Plugins → Kanboard Webhook Integration → Instances
3. Create an instance if one doesn't exist
4. Configure settings in the instance, not the main plugin page

### Finding Department IDs
Run this SQL query on your osTicket database:
```sql
SELECT dept_id, dept_name FROM ost_department;
```

### Testing
Create a test ticket in a mapped department and check:
1. osTicket error logs for any plugin errors
2. Kanboard for the new task
3. Your web server access/error logs
