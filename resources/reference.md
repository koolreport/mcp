# KoolReport

> KoolReport is an open-source PHP reporting framework (koolreport/core on Packagist; PHP 7.0 or newer, tested up to 8.5) with a commercial Pro bundle (koolreport/pro) of extra widgets and features. A report is a PHP class that pipes data from data sources through processes into data stores, plus a view file that renders widgets (tables, charts, pivot tables, inputs) from those data stores. Current version: 6.8.0 (core and Pro).

This is the complete reference for writing KoolReport code. Generated 2026-11-03 from the KoolReport sources; class and method names in it are checked against the code.

## Install

Core is on Packagist:

```bash
composer require koolreport/core
```

The Pro bundle and every Pro package (pivot, excel, inputs, drilldown, datagrid, chartjs, ...) come
from KoolReport's private Composer repository, which needs the customer's credentials:

```json
{
    "repositories": [
        {"type": "composer", "url": "https://repo.koolreport.com"}
    ],
    "require": {
        "koolreport/pro": "^6.7"
    }
}
```

Put the credentials in `auth.json` next to `composer.json` (keep it out of version control):
`{"http-basic": {"repo.koolreport.com": {"username": "...", "password": "..."}}}`. Without them
Composer cannot fetch any Pro package; there is no Packagist fallback.

Load KoolReport with Composer's autoloader: `require_once __DIR__ . "/vendor/autoload.php";`.

## Report anatomy

A report is three files:

1. `EmployeeReport.php` — a class extending `\koolreport\KoolReport`. `settings()` returns the
   data source configuration; `setup()` builds the data pipeline into named data stores.
2. `EmployeeReport.view.php` — the view, found by naming convention (class name + `.view.php`,
   same directory). Inside it, `$this` is the report: use `$this->dataStore("name")` and
   `$this->params`.
3. `index.php` — creates the report, runs it, renders it.

```php
<?php
// EmployeeReport.php
class EmployeeReport extends \koolreport\KoolReport
{
    protected function settings()
    {
        return array(
            "dataSources" => array(
                "hr" => array(
                    "connectionString" => "mysql:host=localhost;dbname=hr",
                    "username" => "user",
                    "password" => "secret",
                    "charset" => "utf8",
                ),
            ),
        );
    }

    protected function setup()
    {
        $this->src("hr")
            ->query("SELECT department, salary FROM staff WHERE hired >= :since")
            ->params(array(":since" => $this->params["since"]))
            ->pipe(new \koolreport\processes\Group(array("by" => "department", "avg" => "salary")))
            ->pipe($this->dataStore("salaries"));
    }
}
```

```php
<?php
// EmployeeReport.view.php
use \koolreport\widgets\koolphp\Table;
use \koolreport\widgets\google\BarChart;

Table::create(array(
    "dataSource" => $this->dataStore("salaries"),
    "columns" => array(
        "department" => array("label" => "Department"),
        "salary" => array("label" => "Average salary", "type" => "number", "prefix" => "$", "decimals" => 0),
    ),
));
BarChart::create(array(
    "dataSource" => $this->dataStore("salaries"),
    "columns" => array("department", "salary"),
));
```

```php
<?php
// index.php
require_once __DIR__ . "/vendor/autoload.php";
require_once __DIR__ . "/EmployeeReport.php";

$report = new EmployeeReport(array("since" => "2020-01-01"));   // becomes $this->params
$report->run()->render();   // render() echoes HTML; wrap it in your own <html> page
```

- `run()` executes `setup()` and fills the data stores; `render()` includes the view.
  `render("OtherView")` uses `OtherView.view.php`; `render(true)` returns the HTML instead of echoing it.
- Widgets load their own JS/CSS; KoolReport serves them from the package folders, so `vendor/`
  must be reachable under the web server's document root (or set the `assets` setting to copy
  them to a public folder).

## Data sources

Each entry in `"dataSources"` is a name => config array. `class` picks the data source; without
`class` it is `\koolreport\datasources\PdoDataSource`. The keys differ per class — do not mix them:

| class | required keys | notes |
|---|---|---|
| `PdoDataSource` (default) | `connectionString` (PDO DSN, e.g. `mysql:host=localhost;dbname=shop`), `username`, `password` | `charset` optional. Works for MySQL, PostgreSQL, SQL Server, SQLite, Oracle via PDO. |
| `MySQLDataSource` | `host`, `username`, `password`, `dbname` | mysqli. `port` (3306), `charset`. A `connectionString` is ignored here, which gives "No database selected". |
| `PostgreSQLDataSource`, `SQLSRVDataSource`, `OracleDataSource` | native drivers | |
| `CSVDataSource` | `filePath` | `fieldSeparator` (default `,`), `charset`, `firstRowData` (default false = first row is the header). |
| `ArrayDataSource` | `data` | `dataFormat`: `"associate"` (default; list of assoc rows) or `"table"` (first row = column names). |
| `ExcelDataSource` (koolreport/excel) | `filePath` | `\koolreport\excel\ExcelDataSource`; `firstRowData`, `sheetName`, `sheetIndex`. |

Class names are written as strings: `"class" => "\koolreport\datasources\CSVDataSource"`.

Querying: `$this->src("name")->query($sql)` for SQL sources; bind values with
`->params(array(":id" => $value))` — never concatenate user input into SQL. Non-SQL sources
(CSV, array, Excel) are piped directly: `$this->src("csv")->pipe(...)`.

```php
<?php
return array(
    "dataSources" => array(
        "shop" => array(
            "class" => "\koolreport\datasources\MySQLDataSource",
            "host" => "localhost",
            "username" => "user",
            "password" => "secret",
            "dbname" => "shop",
            "charset" => "utf8",
        ),
        "targets" => array(
            "class" => "\koolreport\datasources\CSVDataSource",
            "filePath" => __DIR__ . "/targets.csv",
            "fieldSeparator" => ";",
        ),
        "manual" => array(
            "class" => "\koolreport\datasources\ArrayDataSource",
            "dataFormat" => "table",
            "data" => array(
                array("team", "goal"),
                array("Alpha", 1200),
                array("Beta", 950),
            ),
        ),
    ),
);
```

## Processes (the data pipeline)

Processes transform rows between a data source and a data store:
`$this->src(...)->query(...)->pipe(new Process(...))->pipe(...)->pipe($this->dataStore("x"))`.
They live in `koolreport\processes` (core) unless noted. A pipeline can branch: keep a node in a
variable and pipe it into several processes or data stores.

The processes that exist are listed in the next section. There is no `Cast`, `Cut`, `Rename`,
`Where`, `Aggregate` or `Pivot` in `koolreport\processes` — use the ones listed.

Most used, with their real parameter shapes:

```php
<?php
use \koolreport\processes\Group;
use \koolreport\processes\Sort;
use \koolreport\processes\Limit;
use \koolreport\processes\Filter;
use \koolreport\processes\CalculatedColumn;
use \koolreport\processes\ColumnMeta;
use \koolreport\processes\ColumnRename;
use \koolreport\processes\OnlyColumn;
use \koolreport\processes\TimeBucket;
use \koolreport\processes\Custom;

$this->src("shop")->query("SELECT category, sku, price, cost, qty, sold_at FROM sales")
    // computed columns: a function of the row (see the note below on string expressions)
    ->pipe(new CalculatedColumn(array(
        "revenue" => function ($row) { return $row["price"] * $row["qty"]; },
        "profit" => function ($row) { return ($row["price"] - $row["cost"]) * $row["qty"]; },
    )))
    // keep rows matching ALL conditions: array(column, operator, value[, value2])
    // operators: = != > >= < <= contain notContain startWith endWith between notBetween in notIn like
    ->pipe(new Filter(array(
        array("qty", ">", 0),
        array("category", "in", array("Bikes", "Parts")),
    )))
    // bucket a date column: date, week, month, quarter, year, hourofday, dayofweek, ...
    ->pipe(new TimeBucket(array("sold_at" => "month")))
    // group: "by" columns, then sum/count/avg/min/max => column list (string or array)
    ->pipe(new Group(array("by" => "category,sold_at", "sum" => "revenue,profit", "count" => "sku")))
    ->pipe(new Sort(array("revenue" => "desc")))
    ->pipe(new Limit(array(10)))            // array(limit, offset)
    ->pipe(new ColumnRename(array("sold_at" => "month")))
    ->pipe(new OnlyColumn(array("category", "month", "revenue", "profit")))
    ->pipe(new ColumnMeta(array("revenue" => array("type" => "number", "prefix" => "$"))))
    ->pipe(new Custom(function ($row) { $row["category"] = strtoupper($row["category"]); return $row; }))
    ->pipe($this->dataStore("top_categories"));
```

`CalculatedColumn` also accepts a string expression such as `"{price}*{qty}"`, but string
expressions are fragile: in versions before core 6.8.1 they fail on text values (`"{first}.' '.{last}"`
breaks on the first name). Always use a function.

Three processes take an unusual parameter shape; write them exactly like this:

```php
<?php
new \koolreport\processes\RowNumColumn("rank", 1);                 // column name, first number
new \koolreport\processes\StringCase(array("upper" => "name, city")); // case => columns (also lower, first-cap, all-cap)
new \koolreport\processes\DifferenceColumn(array("change" => "sales")); // new column => source column; no options
new \koolreport\processes\NumberRange(array("creditLimit" => array(            // label => array(from, to), null = open end
    "low" => array(null, 50000), "medium" => array(50000, 100000), "high" => array(100000, null),
)));
```

`Group` overwrites the aggregated columns in place: `"sum" => "revenue"` keeps the name `revenue`.
`Sort` takes column => "asc"|"desc" (or a compare function). Joining two pipelines:
`new \koolreport\processes\Join($left, $right, array("customerId" => "id"))` (also `LeftJoin`, ...).

Pivot and Cube processes are in their own packages (see "Pivot tables" and "Cube").

## Process list

The complete list, generated from the package sources. Anything not listed does not exist.

- `koolreport\cleandata`: Describe, DoFill, DropNull, FillNull
- `koolreport\core`: ProcessGroup, ProcessGroupEnd
- `koolreport\cube\processes`: Cube, SuperCube
- `koolreport\pivot\processes`: Pivot, Pivot2D, PivotExtract, PivotSQL
- `koolreport\processes`: AccumulativeColumn, AggregatedColumn, AppendRow, CalculatedColumn, ColumnMeta, ColumnRename, ColumnsSort, CopyColumn, Count, Custom, DateTimeFormat, DifferenceColumn, Filter, Forward, FullJoin, Group, InnerJoin, Join, JsonColumn, JsonSpread, LeftJoin, Limit, Map, NumberBucket, NumberRange, OnlyColumn, OuterJoin, RemoveColumn, RightJoin, RowNumColumn, Shuffle, Sort, StringCase, StringTrim, TimeBucket, Transpose, Transpose2, TypeAssure, ValueMap
- `koolreport\statistics`: Statistics

## Widgets: common settings

Every widget is rendered in the view with `ClassName::create(array(...))`. Settings shared by most widgets:

- `"dataSource" => $this->dataStore("name")` — the data to show. Pass the data store object:
  it works in every version. Core 6.8.0 and later also accept the data store's name as a string
  (`"dataSource" => "name"`); 6.7.1 and earlier fail on that with
  `count(): Argument #1 must be of type Countable|array`. An array of rows or a pipeline node also works.
- `"columns"` — which columns to show and how. A list of names, or name => settings:
  `label`, `type` (`"number"`, `"string"`, `"datetime"`, `"date"`), `prefix`, `suffix`,
  `decimals`, `thousandSeparator`, `decimalPoint`, `formatValue` (always a function:
  `function ($value, $row) { return ...; }`; a string such as `"@value*2"` is fragile and, before
  core 6.8.1, fails on text columns),
  `cssStyle`. For charts the first column is the category axis and each further column is a series.
- `"title"` for charts; `"width"`, `"height"` for charts. Pixels (`400` or `"400px"`) work
  everywhere; CSS lengths such as `"100%"` work for most libraries, but not for koolreport/d3
  before 1.7.2 (Pro 6.8.0), where they render nothing.
- `"options"` — passed through to the underlying JS library (Google Charts, Chart.js, ApexCharts,
  ECharts, DataTables). Use that library's option names.
- `"clientEvents"` — JS event handlers as strings: `"itemSelect" => "function(params){ ... }"`.
- `"name"` — a JS variable name for the widget instance (needed for drill-down `next()` calls).

Number columns need `"type" => "number"` when the values arrive as strings (CSV, some drivers).

## Widget catalog

Every widget class in the Pro bundle, generated from the package sources. Use the full
namespace; a class name without the right namespace is the most common error.

- `koolreport\amazing`: ChartCard, DualChartCard, GaugeCard, IndicatorCard, ProgressCard, SimpleCard
- `koolreport\apexcharts`: AreaChart, BarChart, BoxplotChart, BrushCharts, BubbleChart, CandlestickChart, Chart, ColumnChart, ComboChart, DonutChart, FunnelChart, HeatmapChart, LineChart, PieChart, PolarAreaChart, RadarChart, RadialBarChart, RangeAreaChart, RangeBarChart, ScatterChart, SynchCharts, TimeLineChart, TreemapChart
- `koolreport\barcode`: BarCode, QRCode
- `koolreport\chartjs`: AreaChart, BarChart, BubbleChart, Chart, ColumnChart, ComboChart, DonutChart, LineChart, PieChart, PolarChart, RadarChart, ScatterChart, Timeline
- `koolreport\charttable`: ChartTable
- `koolreport\d3`: AreaChart, BarChart, C3Chart, ColumnChart, DonutChart, FunnelChart, GaugeChart, LineChart, PieChart, ScatterChart, SplineChart, Waterfall
- `koolreport\datagrid`: DataTables
- `koolreport\drilldown`: CustomDrillDown, DrillDown, DrillThrough, LegacyDrillDown, MultiView
- `koolreport\echarts`: AreaChart, Bar3DChart, BarChart, BoxplotChart, CandlestickChart, Chart, ChordChart, ColumnChart, ComboChart, CustomChart, DonutChart, FunnelChart, GaugeChart, GraphChart, HeatmapChart, Line3DChart, LineChart, Lines3DChart, Map3DChart, MapChart, ParallelChart, PictorialBarChart, PieChart, RadarChart, SankeyChart, ScatterChart, SunburstChart, ThemeRiverChart, TreeChart, TreemapChart
- `koolreport\excel`: AreaChart, AreaChart3D, BarChart, BarChart3D, BubbleChart, CandleChart, Chart, DonutChart, DoughnutChart, Hyperlink, Image, LineChart, LineChart3D, PieChart, PieChart3D, PivotMatrix, PivotTable, RadarChart, ScatterChart, StockChart, SurfaceChart, SurfaceChart3D, Table, Text, Widget
- `koolreport\inputs`: BSelect, CheckBoxList, DateRangePicker, DateTimePicker, DateTimePicker2, InputControl, MultiSelect, RadioList, RangeSlider, Select, Select2, TextArea, TextBox
- `koolreport\morris_chart`: Area, Bar, Donut, Line, Morris
- `koolreport\pivot\widgets`: PivotMatrix, PivotTable
- `koolreport\sparklines`: Bar, Box, Bullet, Chart, Line, Pie, Tristate
- `koolreport\visualquery`: VisualQuery
- `koolreport\widgets\google`: AreaChart, BarChart, BubbleChart, CandlestickChart, Chart, ColumnChart, ComboChart, DonutChart, Gauge, GeoChart, Histogram, LineChart, Map, OrgChart, PieChart, Sankey, ScatterChart, SteppedAreaChart, Table, Timeline, TreeMap, WordTree
- `koolreport\widgets\koolphp`: Card, Table

## Table (core)

`\koolreport\widgets\koolphp\Table` renders an HTML table server-side.

```php
<?php
\koolreport\widgets\koolphp\Table::create(array(
    "dataSource" => $this->dataStore("orders"),
    "columns" => array(
        "customer" => array("label" => "Customer", "footerText" => "Total"),
        "amount" => array("type" => "number", "prefix" => "$", "footer" => "sum"),
    ),
    "showFooter" => true,       // or "top"; the footer row only renders when this is set
    "paging" => array("pageSize" => 25),
    "cssClass" => array("table" => "table table-striped"),
));
```

Column `footer` is an aggregate (`sum`, `avg`, `min`, `max`, `count`) and `footerText` may use
`@value`. Before core 6.8.0 the footer is not shown unless `"showFooter"` is set; from 6.8.0 it is
shown when a column defines one, and `"showFooter" => false` hides it. Setting `"showFooter"`
explicitly works in every version.

## Chart libraries and their namespaces

Chart classes share names across libraries; the namespace picks the library. These are the only
correct namespaces (the full class lists are in the widget catalog below):

| library | package | namespace | examples |
|---|---|---|---|
| Google Charts | core | `koolreport\widgets\google` | `ColumnChart`, `BarChart`, `LineChart`, `PieChart`, `DonutChart`, `AreaChart`, `ComboChart`, `GeoChart` |
| Chart.js | koolreport/chartjs | `koolreport\chartjs` | `ColumnChart`, `BarChart`, `LineChart`, `PieChart`, `DonutChart` |
| ApexCharts | koolreport/apexcharts | `koolreport\apexcharts` | `ColumnChart`, `LineChart`, `AreaChart`, `PieChart`, `DonutChart` |
| ECharts | koolreport/echarts | `koolreport\echarts` | `ColumnChart`, `BarChart`, `LineChart`, `PieChart`, `DonutChart` |
| D3 / C3 | koolreport/d3 | `koolreport\d3` | `ColumnChart`, `BarChart`, `LineChart`, `PieChart`, `DonutChart` |
| Morris | koolreport/morris_chart | `koolreport\morris_chart` | `Line`, `Area`, `Bar`, `Donut` |
| Sparklines | koolreport/sparklines | `koolreport\sparklines` | `Line`, `Bar`, `Pie` |

There is no `koolreport\googlecharts` or `koolreport\google` namespace.

Two libraries differ from the rest:

- Morris (`koolreport\morris_chart`) is a jQuery plugin and the widget does not load jQuery. The
  page must load jQuery in `<head>` first, or the chart fails with "jQuery is not defined".
- Sparklines (`koolreport\sparklines`) draw one series, chosen with `"column" => "amount"`
  (singular). Sparklines 1.5.0 and earlier ignore `"columns"` and chart the first column of the data.

```php
<?php
\koolreport\chartjs\LineChart::create(array(
    "title" => "Signups per week",
    "dataSource" => $this->dataStore("signups"),
    "columns" => array(
        "week" => array("label" => "Week"),
        "signups" => array("label" => "Signups", "type" => "number"),
        "trials" => array("label" => "Trials", "type" => "number"),
    ),
));
```

## DataTables grid (koolreport/datagrid)

`\koolreport\datagrid\DataTables` wraps the DataTables JS grid; everything in `"options"` is a
DataTables option.

```php
<?php
\koolreport\datagrid\DataTables::create(array(
    "dataSource" => $this->dataStore("products"),
    "columns" => array("name", "vendor", "price" => array("type" => "number", "prefix" => "$")),
    "options" => array("searching" => true, "paging" => true, "pageLength" => 25, "order" => array(array(2, "desc"))),
));
```

For large tables add `"serverSide" => true` with a `"method" => "post"`; the report must then be
run per request.

## Pivot tables (koolreport/pivot)

Two parts: the `Pivot` process (namespace `koolreport\pivot\processes`) in `setup()`, and the
`PivotTable` or `PivotMatrix` widget (namespace `koolreport\pivot\widgets`) in the view.

```php
<?php
// setup()
$this->src("shop")
    ->query("SELECT region, YEAR(sold_at) AS year, category, revenue FROM sales")
    ->pipe(new \koolreport\pivot\processes\Pivot(array(
        "dimensions" => array("column" => "year", "row" => "region, category"),
        "aggregates" => array("sum" => "revenue", "count" => "revenue"),
    )))
    ->pipe($this->dataStore("sales_pivot"));
```

```php
<?php
// view
\koolreport\pivot\widgets\PivotTable::create(array(
    "dataStore" => $this->dataStore("sales_pivot"),
    "measures" => array("revenue - sum"),
    "rowCollapseLevels" => array(0),
));
```

Measures are named `"<column> - <aggregate>"`.

## Cube / cross-tab (koolreport/cube)

`\koolreport\cube\processes\Cube` turns rows into a two-dimensional cross-tab: one output row per
`row` value, one column per distinct `column` value (plus `{{all}}`), each cell aggregated with one
of `sum`, `count`, `avg`, `min`, `max`, `count percent`, `sum percent`, `count distinct`.

```php
<?php
$this->src("support")
    ->query("SELECT agent, priority, ticket_id FROM tickets")
    ->pipe(new \koolreport\cube\processes\Cube(array(
        "row" => "agent",
        "column" => "priority",
        "count" => "ticket_id",
    )))
    ->pipe($this->dataStore("tickets_by_agent"));
```

Show the result with any table widget, e.g. `\koolreport\widgets\koolphp\Table`.

## Drill-down (koolreport/drilldown)

`\koolreport\drilldown\DrillDown` shows one level at a time. Each level has a `title` and a
`content` function that receives the accumulated `$params`; a chart's client event calls
`<name>.next({...})` to go one level down. Data is queried inside the view with
`$this->src(...)`, because each level depends on the clicked value.

```php
<?php
use \koolreport\drilldown\DrillDown;
use \koolreport\widgets\google\ColumnChart;

DrillDown::create(array(
    "name" => "regionDrill",
    "title" => "Revenue",
    "levels" => array(
        array(
            "title" => "All regions",
            "content" => function ($params, $scope) {
                ColumnChart::create(array(
                    "dataSource" => $this->src("shop")->query("SELECT region, SUM(revenue) AS revenue FROM sales GROUP BY region"),
                    "columns" => array("region", "revenue" => array("type" => "number")),
                    "clientEvents" => array(
                        "itemSelect" => "function(params){ regionDrill.next({region: params.selectedRow[0]}); }",
                    ),
                ));
            },
        ),
        array(
            "title" => function ($params, $scope) { return $params["region"]; },
            "content" => function ($params, $scope) {
                ColumnChart::create(array(
                    "dataSource" => $this->src("shop")
                        ->query("SELECT city, SUM(revenue) AS revenue FROM sales WHERE region = :region GROUP BY city")
                        ->params(array(":region" => $params["region"])),
                    "columns" => array("city", "revenue" => array("type" => "number")),
                ));
            },
        ),
    ),
));
```

## Inputs and filter forms (koolreport/inputs)

Inputs bind to report params. In the report class use the traits `\koolreport\inputs\Bindable`
and `\koolreport\inputs\POSTBinding` (or `GETBinding`), give defaults in `defaultParamValues()`
and map param => input name in `bindParamsToInputs()`. Read the values with `$this->params` in
`setup()`.

```php
<?php
class TicketReport extends \koolreport\KoolReport
{
    use \koolreport\inputs\Bindable;
    use \koolreport\inputs\POSTBinding;

    protected function defaultParamValues()
    {
        return array(
            "period" => array("2024-01-01 00:00:00", "2024-03-31 23:59:59"),
            "priorities" => array("high"),
        );
    }

    protected function bindParamsToInputs()
    {
        return array("period" => "periodInput", "priorities" => "priorityInput");
    }

    protected function settings()
    {
        return array("dataSources" => array("support" => array(
            "connectionString" => "mysql:host=localhost;dbname=support", "username" => "u", "password" => "p",
        )));
    }

    protected function setup()
    {
        $this->src("support")
            ->query("SELECT DISTINCT priority FROM tickets")
            ->pipe($this->dataStore("priority_options"));

        $this->src("support")
            ->query("SELECT ticket_id, opened_at, priority FROM tickets WHERE opened_at BETWEEN :start AND :end AND priority IN (:priorities)")
            ->params(array(
                ":start" => $this->params["period"][0],
                ":end" => $this->params["period"][1],
                ":priorities" => $this->params["priorities"],
            ))
            ->pipe($this->dataStore("tickets"));
    }
}
```

```php
<?php
// view: inputs must sit inside a <form> that posts back to the same page
use \koolreport\inputs\DateRangePicker;
use \koolreport\inputs\Select2;
?>
<form method="post">
    <?php DateRangePicker::create(array("name" => "periodInput")); ?>
    <?php Select2::create(array(
        "name" => "priorityInput",
        "multiple" => true,
        "dataStore" => $this->dataStore("priority_options"),
        "dataBind" => "priority",
    )); ?>
    <button type="submit">Apply</button>
</form>
```

Other inputs in `koolreport\inputs`: `TextBox`, `Select`, `MultiSelect`, `BSelect`, `CheckBoxList`,
`RadioList`, `DateTimePicker`, `RangeSlider`. An array param bound with `IN (:name)` is expanded
by KoolReport.

## Export to Excel (koolreport/excel)

Add the trait `\koolreport\excel\ExcelExportable` to the report class, then in a separate endpoint
(e.g. `export.php`) run the report and export. There is no `exportToXLSX()` on this trait (that is
`\koolreport\excel\BigSpreadsheetExportable`, for very large data).

CSV and JSON come from a separate trait, `\koolreport\excel\CSVExportable`:
`$report->run()->exportToCSV("tickets")->toBrowser("tickets.csv")` (the argument is a data store
name), and `exportToJSON(...)` the same way. A report can use both traits.

Simplest Excel export — data stores as sheets:

```php
<?php
// export.php
require_once __DIR__ . "/vendor/autoload.php";
require_once __DIR__ . "/TicketReport.php";

$report = new TicketReport;
$report->run()
    ->exportToExcel(array("dataStores" => array("tickets" => array())))
    ->toBrowser("tickets.xlsx");      // or ->saveAs("/path/tickets.xlsx")
```

With a template — `exportToExcel("TicketReportExcel")` renders `TicketReportExcel.view.php`,
whose structure is fixed: each top-level `<div sheet-name="...">` is a worksheet and each div inside
it is one block (text, `\koolreport\excel\Table`, `\koolreport\excel\PivotTable`, charts). Any
other markup (for example `<sheet>` elements) has no content: excel 12.0.0 and earlier (Pro 6.7.1)
export an empty workbook without an error, and excel 12.0.1 (Pro 6.8.0) throws an exception that
explains the required structure.

```php
<?php
// TicketReportExcel.view.php
use \koolreport\excel\Table;
?>
<div sheet-name="Tickets">
    <div cell="A1">Tickets by priority</div>
    <div cell="A3">
        <?php Table::create(array("dataSource" => $this->dataStore("tickets"))); ?>
    </div>
</div>
```

## Query builder (koolreport/querybuilder)

Build SQL with `\koolreport\querybuilder\DB::table(...)` and pass it to `query()` wrapped in the
dialect: `\koolreport\querybuilder\MySQL::type($query)` (also `PostgreSQL`, `SQLServer`, `Oracle`,
`SQLite`). Aggregates take only the column; name the result with `->alias()` right after:

```php
<?php
use \koolreport\querybuilder\DB;
use \koolreport\querybuilder\MySQL;

$this->src("shop")
    ->query(MySQL::type(
        DB::table("sales")
            ->join("stores", "stores.id", "=", "sales.store_id")
            ->select("stores.city")
            ->sum("sales.revenue")->alias("revenue")
            ->count("sales.id")->alias("orders")
            ->where("sales.sold_at", ">=", "2024-01-01")
            ->groupBy("stores.city")
            ->orderBy("revenue", "desc")
            ->limit(10)
    ))
    ->pipe($this->dataStore("top_cities"));
```

There is no `sum($column, $alias)` form and no `QueryBuilderEnabled` trait.

## KPI card (core)

```php
<?php
\koolreport\widgets\koolphp\Card::create(array(
    "title" => "Open tickets",
    "value" => $this->dataStore("open_count")->get(0, "total"),   // a number, or a data store / query
    "baseValue" => 120,                                            // optional, shows the change
    "format" => array("value" => array("decimals" => 0)),
));
```

`DataStore::get($row, $column)` reads one cell; `->sum("col")`, `->avg("col")`, `->count()`,
`->min()`, `->max()` aggregate a whole store.

## Common mistakes

Each line is a mistake AI assistants make with KoolReport, measured in an eval.

- Wrong: `use koolreport\googlecharts\PieChart; / koolreport\google\BarChart` → Right: `koolreport\widgets\google\PieChart`. Google Charts widgets live in koolreport\widgets\google.
- Wrong: `"dataSource" => "sales"` → Right: `"dataSource" => $this->dataStore("sales")`. Core 6.7.1 and earlier fail on a string; the data store object works in every version.
- Wrong: `new \koolreport\processes\Cast(...) / Cut(...)` → Right: `ColumnMeta for types, OnlyColumn/RemoveColumn for columns`. Those classes do not exist; see the process list.
- Wrong: `$report->exportToXLSX("View") with ExcelExportable` → Right: `$report->run()->exportToExcel("View")->toBrowser("file.xlsx")`. exportToXLSX belongs to BigSpreadsheetExportable.
- Wrong: `Excel template using <sheet> elements` → Right: `<div sheet-name="..."><div>...</div></div>`. Only the two-level div structure is read; anything else gives an empty workbook (excel 12.0.0) or an exception (12.0.1+).
- Wrong: `"class" => MySQLDataSource with "connectionString"` → Right: `MySQLDataSource takes host/dbname/username/password; or omit class and keep connectionString (PDO)`. Otherwise: No database selected.
- Wrong: `->sum("qty", "total")` → Right: `->sum("qty")->alias("total")`. Query builder aggregates take one argument.
- Wrong: `"footer" => "sum" without showFooter` → Right: `add "showFooter" => true`. Before core 6.8.0 no footer renders otherwise; explicit showFooter works in every version.
- Wrong: `koolreport\d3 chart with "width" => "100%"` → Right: `a pixel width, e.g. "width" => 600, or upgrade to d3 1.7.2 (Pro 6.8.0)`. d3 1.7.1 and earlier pass it to C3 as a number and draw nothing.
- Wrong: `CalculatedColumn with "{first}.' '.{last}" or a formatValue string like "@value km"` → Right: `function ($row) { return $row["first"] . " " . $row["last"]; }`. String expressions break on text values before core 6.8.1; functions work in every version.
- Wrong: `$report->render() before run()` → Right: `$report->run()->render()`. render() shows empty data stores if setup() never ran.

## Widget options

Generated from the widget sources and the documentation (tools/registry). Type and
default come from the code; `—` means none is set.

### koolreport\apexcharts\BrushCharts

| option | type | default | description |
|---|---|---|---|
| `brushMap` | — | '[]' |  |
| `columns` | array/mixed | — | Specify the list of columns will be used to draw chart |

### koolreport\apexcharts\Chart

| option | type | default | description |
|---|---|---|---|
| `autoCategoryColumn` | bool|string|array | '$this->autoCategoryColumn' | Auto-generate categories. `true` = numeric (1,2,3…); `"alphabet"` = A,B,C…; `"numeric"` = 1,2,3…; array = custom label list |
| `clientEvents` | array | 'array()' | KoolReport widget-level event handlers — not ApexCharts' own events. A map of event name => JavaScript function string; the chart fires `init`, `drawing` a... |
| `colorScheme` | array | — | set colors for single or multiple data series |
| `colors` | array | — | the same as colorScheme |
| `columns` | array/mixed | — | Specify the list of columns will be used to draw chart |
| `cssStyles` | array | '[]' | set css styles for chart's parent wrapper div element |
| `fillOpacity` | number/array | — | set series' color filling opacity |
| `fillType` | string/array | — | set series' color filling type |
| `height` | string/number | 'auto' | set chart's height in pixel or relative percentage |
| `jsFiles` | array | '[]' | Extra JavaScript files to load for the chart. A list of known helper names (`moment`, `dayjs`, `dayjsQuarter`) or custom file names resolved under the apexch... |
| `keepNullValue` | boolean | '$this->defaultKeepNullValue' | allow null values in series or not |
| `markersSize` | number/array | — | set series' markers' sizze |
| `maxWidth` | string/numer | 'null' | set chart's maximum width in pixel or relative percentage |
| `onBeforeInit` | mixed | — | JavaScript function string invoked before the chart's client-side creation, as `(function() {...})(widgetName);`. |
| `optionArrayDelimiter` | string | '$this->optionArrayDelimiter' | set delimiter/separator for options string properties |
| `options` | array | 'array()' | Specify extra options for charts, see extra options section for more details. |
| `series` | array | — | The chart's series data in ApexCharts format. When supplied (top-level or via `options` => `['series' => ...]`), `dataSource` becomes optional and the column... |
| `showForSingleSeries` | bool | '$showLegend' | Whether to show the legend when the chart has a single series. When absent, inherits `showLegend` (itself `false` when `sparkline` is enabled). |
| `showLabel` | boolean | 'false' | show labels or not |
| `showLegend` | boolean | '$sparklineEnabled ? false : true' | show legends or not |
| `showMarker` | boolean | 'true' | show markers or not |
| `showStroke` | boolean | 'true' | show stroke or not |
| `showTooltip` | boolean | 'true' | show tooltips or not |
| `showXaxis` | boolean | — | show Xaxis or not |
| `showYaxis` | boolean | — | show Yaxis or not |
| `sparkline` | boolean | — | set chart to sparkline mode for small size charts |
| `stackType` | string | — | Specify if the series of chart will be stacked. |
| `stacked` | boolean | — | Specify if the series of chart will be stacked. |
| `strokeCurve` | string/array | 'smooth' | set series' stroke curve type |
| `strokeWidth` | number/array | — | set series' stroke width |
| `subtitle` | string | — | Subtitle of the chart |
| `subtitleAlignment` | string | 'center' | Subtitle alignment |
| `title` | string | '' | Title of the chart |
| `titleAlignment` | string | 'center' | Title alignment |
| `type` | string | 'bar' | The ApexCharts chart type. Only honoured on the base `Chart` class; concrete chart classes (BarChart, LineChart, ...) preset their own type and ignore this p... |
| `uniqueCategories` | boolean | '$this->defaultUniqueCategories' | set unique or duplicated categories |
| `width` | string/numer | '100%' | set chart's width in pixel or relative percentage |
| `widthHeightAutoRatio` | number | — | set chart's width to height ratio in case at least one of them is not fixed in pixel |
| `xTitle` | string | — | Xaxis title of the chart |
| `yTitle` | string | — | Title text for the Y axis, mapped to ApexCharts `yaxis.title.text`. |

### koolreport\apexcharts\SynchCharts

| option | type | default | description |
|---|---|---|---|
| `columns` | array/mixed | — | Specify the list of columns will be used to draw chart |

### koolreport\chartjs\Chart

| option | type | default | description |
|---|---|---|---|
| `additionalPlugins` | — | 'array()' |  |
| `backgroundColor` | — | 'self::AUTO_COLOR' |  |
| `backgroundOpacity` | number | 0.5 | Specify opacity of bar background |
| `borderColor` | — | 'self::AUTO_COLOR' |  |
| `chartjsClient` | — | '$chartjsClientDefault' |  |
| `clientEvents` | — | 'array()' |  |
| `colorScheme` | — | — |  |
| `columns` | array/mixed | — | Specify the list of columns will be used to draw chart |
| `data` | array | — | An array of data. To be used in case `dataSource` is null |
| `height` | — | — |  |
| `inlinePlugins` | array | '[]' | Specify direct inline plugins. |
| `options` | array | 'array()' | Specify extra options for charts, see extra options section for more details. |
| `plugins` | array | 'array()' | Specify multiple plugins for charts. |
| `title` | string | — | Title of the charts |
| `type` | — | — |  |
| `width` | — | — |  |

### koolreport\widgets\koolphp\Card

| option | type | default | description |
|---|---|---|---|
| `baseValue` | mixed | — | The reference value for the trend indicator (the percentage change from it is shown). Accepts a scalar, or a DataStore/DataSource/Process object (first cell ... |
| `cssClass` | array | 'array()' | CSS classes for card elements. A map of element => class string; keys: `card`, `value`, `indicator`, `title`, `negative`, `positive`, plus `upIcon`/`downIcon... |
| `cssStyle` | array | 'array()' | Inline CSS styles for card elements. A map of element => CSS declaration string; keys: `card`, `value`, `indicator`, `title`, `negative`, `positive`. The `ne... |
| `format` | array | 'array()' | Number/string formatting for the value and the indicator. A map with `value` and `indicator` keys, each a format config (e.g. `["prefix" => "$"]`); the indic... |
| `href` | string | — | Make the card clickable. A URL, or a JavaScript function string (starting with `function`) invoked on click. |
| `indicator` | string | 'percent' | How to compute the trend indicator. Accepts `"percent"` (percentage change vs `baseValue`), `"different"` (absolute difference), or a callable `function($val... |
| `indicatorTitle` | string | 'Compared to previous {baseValue}' | Tooltip title for the indicator. `{baseValue}` and `{value}` placeholders are replaced with the formatted values. |
| `title` | string | — | The card's title. Accepts a plain string, or a callable `function($value)` returning the title (called with the card's value). |
| `value` | mixed | — | The primary value displayed on the card. Accepts a scalar, or a DataStore/DataSource/Process object (its first row's first column is used). |

### koolreport\widgets\google\Chart

| option | type | default | description |
|---|---|---|---|
| `clientEvents` | array | 'array()' | Client-side event handlers. A map of event name => JavaScript function string. Handlers are invoked for the wrapper's own events: `itemSelect`, `rowSelect`, ... |
| `colorScheme` | array | — | List of colors applied to chart, for example: `"colorScheme"=>array("#000","#444","#888")` |
| `columns` | array | 'null' | List of columns and its settings |
| `data` | array | — | The chart's data as a PHP array of associative rows. Column types are guessed from the first row. Prefer `dataSource`/`dataStore` for datastore-backed charts. |
| `height` | string | '400px' | Height of chart in `px` or `%` |
| `language` | string | '$this->language' | Locale passed to Google's `google.charts.load()` for chart UI text (tooltips, legends). |
| `mapsApiKey` | string | '' | Google Maps API key, required by map-based chart packages (GeoChart, Map) when Google must load the Maps API. |
| `options` | array | 'array()' | This is the options for specific charts. It may vary from charts to charts so for more information, please look at example for specific chart below and its o... |
| `pointerOnHover` | bool | — | Show a pointer (hand) cursor over chart elements on hover. |
| `stability` | string | '$this->stability' | Version selector for Google's Charts loader. Commonly `current` or `latest`; an explicit version string is also accepted and forwarded verbatim. |
| `title` | string | — | Title of chart |
| `type` | string | — | The Google visualization chart class name (e.g. `"ColumnChart"`, `"PieChart"`, `"GeoChart"`). Only honoured on the base `Chart` class; concrete classes set i... |
| `width` | string | '100%' | Width of chart in `px` or `%` |

### koolreport\widgets\google\Table

| option | type | default | description |
|---|---|---|---|
| `clientEvents` | array | — | Settings for table client events |
| `columns` | array | 'array()' | List of columns to display and their settings |
| `cssClass` | array | 'array()' | Set css class for table, row and cell |
| `data` | array | — | The table's rows as a PHP array of associative rows. Column types are guessed from the first row. Prefer `dataSource`/`dataStore` for datastore-backed tables. |
| `emptyValue` | mixed | 0 | Value rendered in a cell whose data key is missing or `null`. Note: it does NOT replace empty strings or `0` values, only absent/null cells. |
| `excludedColumns` | array | 'array()' | Column keys to hide from the table. A list of column names that are dropped from the rendered columns. |
| `groupCellsInColumns` | array | '$rowspan' | An alias of `removeDuplicate` |
| `grouping` | array | — | Group rows by column value. A map of column key => config, or column key => column-name string (shorthand). Config keys: `top`, `bottom`, `calculate`, `css`,... |
| `headers` | array | 'array()' | Extra header rows above the column labels. A list of rows; each row is a map of header text => attribute array (`["colSpan" => "2", "align" => "center"]`), w... |
| `paging` | array | — | Settings for table paging |
| `removeDuplicate` | array | 'array()' | List of column names or orders that you want to remove duplicate |
| `responsive` | bool | 'false' | Wrap the table in a horizontally-scrollable container. |
| `rowspan` | array | '$removeDuplicate' | An alias of `removeDuplicate` |
| `showFooter` | mixed | — | Show a footer row with aggregated column values. `"top"` renders the footer above the body; any other truthy value (e.g. `true`, `"bottom"`) renders it below. |
| `showHeader` | bool | 'true' | Render the `` (the `headers` group rows and the column-label row). Set `false` to suppress the header entirely. |
| `sorting` | array | 'array()' | Sort the table by column. An associative map of column name => `"asc"`/`"desc"`, or column name => comparator callable. |

### koolreport\d3\C3Chart

| option | type | default | description |
|---|---|---|---|
| `clientEvents` | — | 'array()' |  |
| `colorScheme` | — | — |  |
| `height` | — | — |  |
| `options` | — | 'array()' |  |
| `width` | — | — |  |

### koolreport\datagrid\DataTables

| option | type | default | description |
|---|---|---|---|
| `DataTablesCss` | string | — | Override the DataTables CSS file to load. A file name resolved under the DataTables clients folder (defaults to the theme's bundled `datatables` stylesheet). |
| `DataTablesJs` | string | — | Override the DataTables JavaScript library file to load. A file name resolved under the DataTables clients folder (defaults to the theme's bundled `datatable... |
| `ajaxIdParam` | string | 'id' | Name of the request parameter carrying the row id in server-side edit/delete requests. |
| `ajaxUrl` | string | 'null' | Override the AJAX endpoint used for server-side data requests, for when it differs from the current report URL. |
| `attributes` | array | 'array()' | Optional. You could set custom attributes for the table's table, th, tr, td, tf elemens. |
| `clientEvents` | array | '[]' | Map of DataTables event name => JavaScript function string, registered on the DataTable with `dt.on(event, fn)`. Note that when row grouping is active the wi... |
| `clientRowGroup` | array | '[]' | Group rows client-side by column. A map of column key => config with keys `direction` (default `asc`), `calculate`, `top`, `bottom`. Adds the RowGroup plugin... |
| `clientRowSpan` | array | '[]' | Merge vertically-consecutive identical cells client-side for the listed columns, and sort by them. Accepts a column key => config map (`['direction' => 'asc'... |
| `collapseIcon` | string | '<i class=\\\'far fa-minus-square\\\' aria-hidden=\\\'true\\\'></i>' | HTML for the collapse icon shown in client row-group headers. Injected into the `{expandCollapseIcon}` placeholder of a group's `top`/`bottom` templates. |
| `columns` | array | 'array()' | Optional. List all the columns you want to display in data table together with its settings |
| `complexHeaderLabels` | boolean | 'false' | Optional. Apply complex headers on column labels instead of column keys. |
| `complexHeaders` | boolean | '$this->complexHeaderLabels' | Optional. Use DataTables' complex headers with rowspan and colspan. Defaults to the value of `complexHeaderLabels` (itself `false`), so enabling `complexHead... |
| `cssClass` | array | 'array()' | Optional. You could set css classes for the table's table, th, tr, td, tf elemens. |
| `cssStyle` | array | 'array()' | Inline CSS styles for table elements. A map of element => CSS declaration string or callable. Keys: `table`, `th`, `tr`, `td`, `tf` (server-rendered), plus `... |
| `data` | array | — | Optional. You may set data directly here in form of associate array |
| `defaultPlugins` | array | '["AutoFill", "ColReorder", "RowGroup", "Select"]' | Optional. Set DataTables' default plugins to load with. |
| `deleteFunction` | callable | — | PHP callable invoked server-side to perform a row delete. Called with the row's `deleteParams` (from `$_POST[]['deleteParams']`); its return value is JSON-en... |
| `editButtons` | array | '[]' | Custom buttons for the row-edit column. A map of button key => settings merged over the built-in `add`/`edit`/`delete` buttons. |
| `emptyValue` | string | '-' | Optional. Empty value is used to replace missing data in rows. |
| `expandIcon` | string | '<i class=\\\'far fa-plus-square\\\' aria-hidden=\\\'true\\\'></i>' | HTML for the expand icon shown in client row-group headers. Injected into the `{expandCollapseIcon}` placeholder of a group's `top`/`bottom` templates. |
| `fastRender` | bool | 'false' | Render all rows client-side for large tables. The server serializes the numeric-indexed rows and DataTables draws them with `deferRender`; the `` is not serv... |
| `groupCellsInColumns` | array | '$rowspan' | Merge vertically-consecutive identical cells for the listed columns (server-side, via the RowsGroup plugin). An alias of `rowspan` — see the `rowspan` row.... |
| `headerSeparator` | string | '-' | Optional. If `complexHeaders` == true, use this separator to separate headers' groups. |
| `insertFunction` | callable | — | PHP callable invoked server-side to perform a row insert. Called with the row's `insertParams` (from `$_POST[]['insertParams']`); the return value is merged ... |
| `method` | string | 'get' | HTTP method for server-side (AJAX) data requests: `get` or `post`, case-insensitive. With server-side processing the request body is read from `$_GET`/`$_POS... |
| `name` | string | — | Optional. You can set the name for your data table if you want to refer to the table later on at client-side. If you don't set we will set random name for table |
| `onBeforeInit` | mixed | — | JavaScript function string invoked once, before the widget's client-side init, as `(function() {...})()`. |
| `options` | array | 'array()' | Optional. This property will hold all the extra settings for data tables |
| `overrideSearchInput` | bool | 'true' | Whether the widget rebinds the DataTables search input. Set `false` when a plugin such as mark.js needs the original binding. |
| `plugins` | array | '[]' | Optional. Set DataTables' additional plugins to load with. |
| `removeDuplicate` | array | '[]' | Merge vertically-consecutive identical cells for the listed columns (server-side, via the RowsGroup plugin). An alias of `rowspan` — see the `rowspan` row.... |
| `rowDetail` | array | '[]' | Row-detail settings as one array. Its `data` key is an alias of `rowDetailData`. |
| `rowDetailData` | mixed | — | HTML shown in an expandable row detail. Accepts a PHP closure `function($row){...}` (called per row, result rendered into a hidden column) or a JavaScript fu... |
| `rowDetailIcon` | bool | 'true' | Show the expand/collapse icon for row detail. Set `false` to expand by clicking the row itself. |
| `rowDetailSelector` | string | '' | CSS selector for the element that toggles row detail, used when `rowDetailIcon` is `false`. |
| `rowDetailServerRender` | bool | 'false' | Render row-detail content server-side into a hidden column instead of building it on the client. |
| `rowGroup` | array | '[]' | Group rows server-side with section headers and aggregates. A map of column key => config with keys `direction` (default `asc`), `calculate`, `top`, `bottom`... |
| `rowspan` | array | '$removeDuplicate' | Merge vertically-consecutive identical cells for the listed columns (server-side, via the RowsGroup plugin). Accepts column names (when `columns` is a plain ... |
| `scope` | array | 'array()' | Optional. Data table will include the scope parameters in each of its ajax call to server-side operation. |
| `searchMode` | string | 'smart' | Optional. One or more of the modes `smart`, `or`, `and`, `exact` combined with the `\|` separator. Matching is case-insensitive, so `"OR"` and `"or"` are equi... |
| `searchOnEnter` | boolean | 'false' | Optional. Make DataTables' filter only when users press Enter on the input field. |
| `searchQuery` | string | '$originalQuery' | SQL query string used for server-side searching, with a `{datatables_search}` placeholder the widget replaces with the generated search conditions. Defaults ... |
| `serverRowGroup` | array | '$this->serverRowGroup' | Group rows server-side with section headers and aggregates. A map of column key => config with keys `direction` (default `asc`), `calculate`, `top`, `bottom`... |
| `serverSide` | boolean | 'false' | Optional. Default value is false. Determine whether data table's operations are performed on server-side or client-side (default). |
| `serverSideInstantSearch` | bool | 'false' | Enable instant (as-you-type) searching with server-side processing. May also be set via `serverSide` => `['instantSearch' => ...]`. |
| `showFooter` | boolean | 'false' | Optional. Show table footer or not, default value is false. |
| `template` | string | 'DataTables' | Name of the template view used to render the widget, resolved under the package's template folder (a theme-specific variant is preferred when one exists). |
| `unminifiedCss` | bool | 'false' | Load the unminified DataTables CSS by dropping the `.min` suffix from the default stylesheet names. Has no effect on an explicit `DataTablesCss` override. |
| `unminifiedJs` | bool | 'false' | Load the unminified DataTables JavaScript by dropping the `.min` suffix from the default and plugin script names. Has no effect on an explicit `DataTablesJs`... |
| `updateFunction` | callable | — | PHP callable invoked server-side to perform a row update. Called with the row's `updateParams` (from `$_POST[]['updateParams']`); the return value is merged ... |

### koolreport\drilldown\DrillDown

| option | type | default | description |
|---|---|---|---|
| `btnBack` | mixed | 'true' | By default, the button Back will shown, give it value `false` you will off the Back button. This property can receive array() to customize cssClass and text ... |
| `clientEvents` | array | 'array()' | Register client-event, see client-event for more details. |
| `css` | mixed | — | Defined css for the most important elements in the widgets, see the __$css__ properties for more details. |
| `cssClass` | mixed | — | Defined css class for the most important elements in the widgets, see the __$cssClass__ properties for more details. |
| `currentLevel` | — | 'array(0, array())' |  |
| `global` | — | — |  |
| `levels` | array | 'array()' | `* Required` List of all levels for drill down report. See the __level properties__ for more detail of settings on each level. |
| `name` | string | — | `*Required` Name of the drill down report or even process. |
| `partialRender` | — | 'false' |  |
| `scope` | array/function | 'array()' | Set the group of parameters which will be kept persistent during the ajax post back of drill-down. |
| `showLevelTitle` | bool | 'true' | Whether title of each level is shown |
| `template` | — | — |  |
| `title` | string | 'DrillDown' | Title that is showed on top of drill-down report. |

### koolreport\echarts\Chart

| option | type | default | description |
|---|---|---|---|
| `clientEvents` | — | '[]' |  |
| `colorScheme` | — | — |  |
| `columns` | — | — |  |
| `height` | — | '400px' |  |
| `options` | — | '[]' |  |
| `series` | — | — |  |
| `title` | — | — |  |
| `width` | — | '100%' |  |

### koolreport\inputs\DateRangePicker

| option | type | default | description |
|---|---|---|---|
| `caret` | — | 'fa fa-caret-down' |  |
| `format` | — | — |  |
| `icon` | — | 'glyphicon glyphicon-calendar fa fa-calendar' |  |
| `maxDate` | — | — |  |
| `minDate` | — | — |  |
| `options` | — | 'array()' |  |
| `ranges` | — | 'array("Today" => DateRangePicker::today(), "Yesterday" => DateRangePicker::yesterday(), "Last 7 days" => DateRangePicker::last7days(), "Last 30 days" => DateRangePicker::last30days(), "This month" => DateRangePicker::thisMonth(), "Last month" => DateRangePicker::lastMonth())' |  |
| `template` | — | — |  |

### koolreport\inputs\Select2

| option | type | default | description |
|---|---|---|---|
| `defaultOption` | — | 'null' |  |
| `multiple` | — | 'false' |  |
| `options` | — | 'array()' |  |
| `placeholder` | — | — |  |
| `singleNoneSelected` | — | 'false' |  |

### koolreport\pivot\widgets\PivotTable

| option | type | default | description |
|---|---|---|---|
| `clientEvents` | — | '[]' |  |
| `columnCollapseLevels` | — | 'array()' |  |
| `columnTotalAtBeginning` | — | 'false' |  |
| `cssClass` | — | 'array()' |  |
| `emptyValue` | — | '-' |  |
| `hideGrandTotalColumn` | — | 'false' |  |
| `hideGrandTotalRow` | — | 'false' |  |
| `hideSubTotalColumns` | — | 'false' |  |
| `hideSubTotalRows` | — | 'false' |  |
| `hideSubtotalColumn` | — | 'Util::get($this->params, \'hideSubTotalColumns\', false)' |  |
| `hideSubtotalColumns` | — | 'Util::get($this->params, \'hideSubtotalColumn\', Util::get($this->params, \'hideSubTotalColumns\', false))' |  |
| `hideSubtotalRow` | — | 'Util::get($this->params, \'hideSubTotalRows\', false)' |  |
| `hideSubtotalRows` | — | 'Util::get($this->params, \'hideSubtotalRow\', Util::get($this->params, \'hideSubTotalRows\', false))' |  |
| `id` | — | 'null' |  |
| `initVisible` | — | 'false' |  |
| `name` | — | '$this->name' |  |
| `paging` | — | 'array()' |  |
| `rowCollapseLevels` | — | 'array()' |  |
| `rowTotalAtBeginning` | — | 'false' |  |
| `selectable` | — | 'false' |  |
| `showDataHeaders` | — | 'false' |  |
| `template` | — | 'PivotTable' |  |
| `totalName` | — | 'Total' |  |
| `width` | — | 'null' |  |

