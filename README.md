# KoolReport MCP

Helps AI coding assistants (Claude Code, Cursor, and others that speak the Model Context Protocol) write
KoolReport reports that work the first time. Install it in a project that uses KoolReport:

```bash
composer require --dev koolreport/mcp
```

## Use it with an assistant

The server speaks the open Model Context Protocol over stdio, so it works with any assistant that can run a
local MCP server. Run the commands from your project root (where `vendor/` is).

**Claude Code**

```bash
claude mcp add koolreport -- php vendor/bin/koolreport-mcp
```

**Cursor**: `.cursor/mcp.json`

```json
{"mcpServers": {"koolreport": {"command": "php", "args": ["vendor/bin/koolreport-mcp"]}}}
```

**VS Code (GitHub Copilot agent mode)**: `.vscode/mcp.json`

```json
{"servers": {"koolreport": {"command": "php", "args": ["vendor/bin/koolreport-mcp"]}}}
```

**OpenAI Codex CLI**: `~/.codex/config.toml`

```toml
[mcp_servers.koolreport]
command = "php"
args = ["vendor/bin/koolreport-mcp"]
```

**Gemini CLI**: `~/.gemini/settings.json`

```json
{"mcpServers": {"koolreport": {"command": "php", "args": ["vendor/bin/koolreport-mcp"]}}}
```

**Windsurf, Cline, Continue, Zed, JetBrains AI Assistant**: add an MCP server in the tool's settings with
command `php` and argument `vendor/bin/koolreport-mcp`.

These formats are the ones each tool documents at the time of writing; check its current docs if one does
not load. Web chat apps (ChatGPT, Claude.ai in the browser) connect to hosted MCP servers only, so they
cannot use this local server. Use `koolreport-check` there or point them at the reference below.

The server gives the assistant four tools:

| tool | what it does |
|---|---|
| `koolreport_check` | Checks report code without running it. It flags classes that do not exist (with the class that was meant), wrong parameter shapes, export calls that name no data store, and pitfalls of specific KoolReport versions |
| `koolreport_find_class` | Says whether a class exists in your installed version; if not, what was probably meant |
| `koolreport_check_spec` | Validates a declarative report spec ([koolreport/spec](https://github.com/koolreport/spec)) before it runs, every problem with its path |
| `koolreport_reference` | Returns sections of the KoolReport reference (data sources, processes, widgets, pivot, inputs, Excel export, ...) |

Classes are read from your project's `vendor/koolreport`, so the answers match the version you have
installed, Pro packages included. Nothing is sent anywhere: the server runs locally over stdio.

## Check code from the command line

For assistants without MCP (or in CI), the same checks run as a command:

```bash
vendor/bin/koolreport-check src/Reports
```

It also validates report specs (`.json` files with a `layout`) when koolreport/spec is installed.

The exit code is 1 when there is an error, so it can run in CI.

## Without MCP

The same reference is published for any assistant at https://www.koolreport.com/llms.txt and
https://www.koolreport.com/llms-full.txt.

## Requirements

PHP 7.0 or newer. No other dependencies.
