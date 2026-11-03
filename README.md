# KoolReport MCP

Helps AI coding assistants (Claude Code, Cursor, and others that speak the Model Context Protocol) write
KoolReport reports that work the first time. Install it in a project that uses KoolReport:

```bash
composer require --dev koolreport/mcp
```

## Use it with an assistant

Claude Code:

```bash
claude mcp add koolreport -- php vendor/bin/koolreport-mcp
```

Cursor (`.cursor/mcp.json`):

```json
{"mcpServers": {"koolreport": {"command": "php", "args": ["vendor/bin/koolreport-mcp"]}}}
```

The server gives the assistant three tools:

| tool | what it does |
|---|---|
| `koolreport_check` | Checks report code without running it. It flags classes that do not exist (with the class that was meant), wrong parameter shapes, export calls that name no data store, and pitfalls of specific KoolReport versions |
| `koolreport_find_class` | Says whether a class exists in your installed version; if not, what was probably meant |
| `koolreport_reference` | Returns sections of the KoolReport reference (data sources, processes, widgets, pivot, inputs, Excel export, ...) |

Classes are read from your project's `vendor/koolreport`, so the answers match the version you have
installed, Pro packages included. Nothing is sent anywhere: the server runs locally over stdio.

## Check code from the command line

```bash
vendor/bin/koolreport-check src/Reports
```

The exit code is 1 when there is an error, so it can run in CI.

## Without MCP

The same reference is published for any assistant at https://www.koolreport.com/llms.txt and
https://www.koolreport.com/llms-full.txt.

## Requirements

PHP 7.0 or newer. No other dependencies.
