# Toggl
MediaWiki extension to provide integration for Toggl

## Configuration Options

### $wgTogglWorkspaceID (default: false)

Default workspace ID that should be used for queries.

## Parser Functions

### Toggl API

#### {{#toggl-workspaces:}}

Returns an unordered list of workspace IDs and their names

#### {{#toggl-workspace:}} tbd

#### {{#toggl-workspace-users:}}

Returns an unordered list of user IDs and their names

#### {{#toggl-workspace-clients:}}

Returns an unordered list of client IDs and their names

#### {{#toggl-workspace-groups:}} tbd

#### {{#toggl-workspace-projects:}}

Returns an unordered list of project IDs and their names

#### {{#toggl-workspace-tasks:}} tbd

#### {{#toggl-workspace-tags:}} tbd

### Toggl Reports API

#### {{#toggl-report-weekly:}} tbd

#### {{#toggl-report-detailed:}} tbd

#### {{#toggl-report-summary:params=}}

Returns the total of hours if no filter for the group or subgroup has been set.

Example:

```
{{#toggl-report-summary:grouping=clients|client_id=1|start_date=2020-01-01|end_date=2020-12-31}}
```

Returns all hours worked for the client with ID “1” for the year 2020.

Params:
- grouping (default: users)
- sub_grouping (default: clients)
- start_date (required)
- end_date (required)

#### {{#toggl-report-summary-hours:}}
