# Changelog

All notable changes to the osTicket Kanboard Plugin will be documented in this file.

## [1.1] - 2025-12-05

### Added
- **Blocked Column Support**: Added support for mapping tickets to a "blocked" column in Kanboard
  - New fifth parameter in department mappings: `blocked_column_id`
  - Tickets automatically move to blocked column when status changes to "On Hold"
  - New `onTicketHold()` function to handle On Hold status changes
  - Comprehensive error logging for debugging blocked column operations

- **Work In Progress (WIP) Column Support**: Added support for mapping tickets to a "work in progress" column
  - New sixth parameter in department mappings: `wip_column_id`
  - Mapping format updated to: `dept_id:project_id:new_column_id:done_column_id:blocked_column_id:wip_column_id`
  - Tickets automatically move to WIP column when status changes to "Open" (e.g., when resuming from "On Hold")
  - New `onTicketOpenStatus()` function to handle Open status changes
  - Enables full workflow tracking: New → WIP → Blocked → WIP → Done

### Changed
- **Department Mapping Parser**: Updated `parseDepartmentMappings()` to handle 6-parameter format
  - Maintains backward compatibility with 4-parameter format (without blocked and WIP columns)
  - Gracefully handles missing blocked_column_id and wip_column_id in configuration
- **Config Interface**: Updated placeholder and hint text in config.php to reflect new mapping format
- **Status Change Detection**: Enhanced `onObjectEdited()` to detect and handle multiple status transitions
  - Closed/Resolved → Done column
  - On Hold → Blocked column  
  - Open → WIP column

### Technical Details
- Status transition workflow:
  - **New tickets**: Created in "new" column
  - **On Hold status**: Moved to "blocked" column (requires blocked_column_id)
  - **Open status**: Moved to "work in progress" column (requires wip_column_id)
  - **Resolved/Closed status**: Moved to "done" column and marked as closed
- All column movements maintain swimlane consistency
- Error logging added for missing column configurations

## [1.0] - Initial Release

### Features
- Kanboard API integration via JSON-RPC
- Automatic task creation in Kanboard when tickets are created in osTicket
- Task assignment synchronization between osTicket and Kanboard
- Automatic task closure and column movement when tickets are resolved/closed
- Department-to-project mapping configuration
- Support for custom swimlanes
- Priority and color mapping from osTicket to Kanboard
- Reference field linking for task lookup