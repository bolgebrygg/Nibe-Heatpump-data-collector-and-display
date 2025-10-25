# Nibe-Heatpump-data-collector-and-display
MyUplink Dual Plugin System: WordPress collector + display. Collector fetches heat pump metrics every 30 min into one raw table. Display shows SVG pivot charts, tables, CSV, and daily production/consumption energy deltas plus efficiency ratio—simple shortcodes, optional REST key, no external chart libs.

MyUplink Dual Plugin System is a lightweight, two‑part WordPress solution for acquiring, storing, and visualizing operational and energy data from a MyUplink‑connected heat pump or HVAC device. The Collector plugin authenticates against the MyUplink API using your client credentials, fetches the selected parameter IDs every thirty minutes, and writes raw, unaggregated readings into a single normalized table (timestamped UTC, with parameter name, unit, and value). This raw storage strategy preserves fidelity and makes later aggregation flexible. The Display plugin converts those stored points into pivot tables, minimalist inline SVG charts, CSV exports (including a streaming full‑history option), single‑value widgets, and daily energy production versus consumption deltas plus a derived efficiency ratio. A single shortcode attribute (energy_pair) triggers on‑the‑fly calculation of daily production and consumption differences from cumulative kWh counters, intentionally limited to day buckets for clarity and correctness. Security features include optional REST API key gating for chart/CSV endpoints and a secret header for forcing manual collection runs. Visualization emphasizes simplicity: forward‑filled bucket logic avoids gaps without averaging, integer tick selection improves numeric readability, and legend checkboxes permit selective overlay of temperature, brine, hot water, and energy metrics. The system is beginner‑friendly—activation, adding IDs, and dropping shortcodes into a page yields immediate dashboards—yet remains extensible (new intervals, dual Y axes, alerts) through contained modifications inside the display layer. This focused architecture delivers transparent energy insight while remaining maintainable, fast, and dependency‑free. It helps owners track seasonal performance trends, validate efficiency improvements, and share clear reports with minimal ongoing maintenance overhead.

# MyUplink Dual Plugin System (Collector + Display)

A beginner-friendly guide to installing, configuring, and using the two WordPress plugins that gather and visualize data from the MyUplink API.

These plugins are intentionally split:
- **Collector Plugin (`myuplink-collector-plugin`)**: Authenticates with MyUplink, periodically fetches selected sensor/energy parameters, and stores raw values in a single clean database table.
- **Display Plugin (`myuplink-display-plugin`)**: Reads the stored data, builds tables, pivot charts (inline SVG – no external chart libraries), exports CSV, and computes energy production/consumption deltas and ratios.

If you are new to WordPress plugins, APIs, or energy monitoring, start here. Follow the steps in order—by the end you will have daily charts, energy metrics, and an understanding of how to extend the system.

---
## 1. What You Get
| Capability | Provided By | Notes |
|------------|-------------|-------|
| Fetch live device parameters from MyUplink | Collector | Every 30 minutes via WP Cron or manual trigger |
| Store numeric values with timestamps | Collector | Single table: `wp_myuplink_points` |
| Configuration UI for API credentials & parameter IDs | Collector | In WP Admin > Settings > MyUplink Collector |
| Pivot chart & tables | Display | Shortcodes usable in posts/pages |
| CSV export (recent / pivot / full historical streaming) | Display | Admin or API key protected |
| Energy production vs. consumption daily delta & ratio | Display | Derived inside pivot chart via `energy_pair` attribute |
| Latest value display | Display | `[myuplink_value]` shortcode |
| Parameter list helper (ID → name) | Display | Admin-only shortcode |

---
## 2. High-Level Architecture
```
MyUplink API --> (Collector plugin cron every 30 min) --> wp_myuplink_points table --> (Display plugin) --> Shortcodes --> Frontend charts/tables
```
- Data is *not* aggregated during collection—raw precision preserved.
- Display plugin aggregates or pivots on demand.
- Energy deltas are computed from cumulative counters (kWh) per day.

---
## 3. Prerequisites
1. A working WordPress site (preferably on HTTPS).  
2. Valid MyUplink API Client ID and Client Secret (get them from the MyUplink developer portal).  
3. Access to install custom plugins (Admin privileges).  
4. Basic familiarity with editing pages/posts (for inserting shortcodes).  

No coding skills required beyond copy/pasting shortcodes.

---
## 4. File & Database Overview
- **Collector plugin folder:** `myuplink-collector-plugin/`
- **Display plugin folder:** `myuplink-display-plugin/`
- **Shared table:** `wp_myuplink_points` (prefix `wp_` may differ depending on your WP install)

Table columns:
```
id (auto increment)
ts (DATETIME UTC)
device_id (string)
parameter_id (BIGINT)
parameter_name (VARCHAR)
parameter_unit (VARCHAR)
value (DECIMAL(20,2))
```
Uniqueness: (device_id, parameter_id, ts) prevents duplicate entries for the same time slice.

---
## 5. Installation (From Scratch)
### 5.1 Download / Copy
1. Place both plugin folders into `wp-content/plugins/`:
   - `wp-content/plugins/myuplink-collector-plugin`
   - `wp-content/plugins/myuplink-display-plugin`
2. Ensure each folder contains its main PHP file (already provided here).

### 5.2 Activate Plugins
1. Log into WordPress Admin.
2. Go to Plugins > Installed Plugins.
3. Activate “MyUplink Data Collector” first, then “MyUplink Data Display.”
4. Activation of collector creates (or verifies) the database table.

### 5.3 Configure Collector
1. Navigate to Settings > MyUplink Collector.
2. Enter Client ID and Client Secret; click Save.
3. Parameter IDs: start with the default list the plugin provided (includes temperature & energy IDs). You can refine later.
4. Use “Test Connection & Fetch Data” button to pull initial data.
5. Confirm “Recent Data” section shows rows.

### 5.4 Optional: Regenerate REST Secret
- This secret is used for direct POST triggers to collect immediately. Regenerate only if compromised.

### 5.5 Configure Display Plugin (Optional API Key)
1. Go to Settings > MyUplink Display.
2. (Optional) Set a REST API Key to allow public chart data endpoints without admin login.
3. Default colors and height can be left as-is initially.

---
## 6. Data Collection Mechanics
- Runs every **30 minutes** using WordPress cron (triggered by site traffic or WP-CLI).  
- If your site has very low traffic, consider an external cron hitting any public page or use a server-level cron to request `wp-cron.php`.  
- Each run: resolves device, fetches selected parameter points from MyUplink, stores them.

Manual fetch options:
- Use Admin “Test Connection & Fetch Data” button.
- REST trigger POST: `https://your-site.example/wp-json/myuplink-collector/v1/collect` with header `X-MyUplink-Secret: <secret>`.

---
## 7. Choosing Parameter IDs
The collector stores whichever numeric IDs you list. To discover or confirm names:
- After initial data pulls, use the display plugin’s admin helper shortcode on a private page:  
  `[myuplink_list_parameters]` (visible only to admins).  
- This lists `parameter_id` alongside `parameter_name` stored.

Common examples (these may vary per installation):
- 4, 13, 14: Temperatures / environmental metrics.
- 28392: Energy production cumulative (kWh).
- 28393: Energy consumption cumulative (kWh).

Always ensure the production/consumption IDs are cumulative counters (monotonically increasing) if you want to compute daily deltas.

### 7.1 Default ID Set (Installed Automatically)
On first activation the collector saves this default comma‑separated list:
```
4,8,10,11,12,13,14,54,781,1708,27335,28392,28393
```
Below is the definitive list of the **default parameter IDs** the collector plugin activates on first installation. Their human-readable names and exact units are fetched dynamically from the MyUplink API and stored in the database; you can view them after the first successful data pull using the shortcode: `[myuplink_list_parameters]` (admin only). Until then, treat the Description column as a helpful orientation, not a guarantee. Replace the Description text with the actual `parameter_name` strings once known if you want perfect accuracy in this README.

| ID    | Included by Default | Name (from API)                 | Unit | Cumulative? | Notes |
|-------|---------------------|---------------------------------|------|------------|-------|
| 4     | Yes                 | Outdoor temperature             | °C   | No         | Ambient reference temperature |
| 8     | Yes                 | Supply line (BT2)               | °C   | No         | Heating circuit supply temp |
| 10    | Yes                 | Return line (BT3)               | °C   | No         | Heating circuit return temp |
| 11    | Yes                 | Hot water top (BT7)             | °C   | No         | Top of tank temperature |
| 12    | Yes                 | Hot water charging (BT6)        | °C   | No         | Charging/coil temperature |
| 13    | Yes                 | Brine in (BT10)                 | °C   | No         | Ground/brine entering heat pump |
| 14    | Yes                 | Brine out (BT11)                | °C   | No         | Ground/brine leaving heat pump |
| 54    | Yes                 | (Device-specific metric)        | —    | No         | Name varies; verify after fetch |
| 781   | Yes                 | Degree minutes                  | DM   | No         | Control metric; negative = deficit |
| 1708  | Yes                 | (Extended device metric)        | —    | No         | Placeholder until name fetched |
| 27335 | Yes                 | energy consumption watt       | —    | No         | Firmware / model dependent |
| 28392 | Yes                 | Energy production total         | kWh  | Yes        | Use in `energy_pair` (prod) |
| 28393 | Yes                 | Energy consumption total        | kWh  | Yes        | Use in `energy_pair` (cons) |

Important:
- The collector stores both the numeric ID and the `parameter_name` returned by the API. That name overrides any guess here.
- Only IDs 28392 & 28393 (cumulative kWh counters) should be placed in `energy_pair` for delta/ratio derivation.
- If any cumulative counter ever resets (value drops), the plugin will mark that day’s delta as null to avoid misleading negative values.

Important: IDs above (except 28392/28393) are *best guesses* based on common deployments. Always trust the `parameter_name` column stored in the database over this table.

### 7.2 Identifying Cumulative vs. Instantaneous
You can decide whether a parameter is cumulative by inspecting successive stored values:
1. Export recent rows for the parameter (use `[myuplink_table_full]`).
2. If values only increase (or occasionally reset after maintenance) it is cumulative.
3. If values fluctuate up and down (like temperatures or power draw), it is instantaneous.

Only cumulative parameters should be placed in `energy_pair` to derive daily deltas. Mixing an instantaneous parameter will produce meaningless or negative deltas.

### 7.3 Updating Descriptions
Once you confirm each parameter name:
1. Edit this README table (if you wish) to replace placeholders.  
2. Or create a new page that lists `[myuplink_list_parameters]` for quick reference without editing files.

### 7.4 Adding / Removing IDs Safely
You can adjust the collector’s ID list at any time. Historical data remains for removed IDs; new ones start accumulating from the moment they are added. No restart required.

---
## 8. Display Plugin Shortcodes
### 8.1 Pivot Chart (SVG)
```
[myuplink_pivot_chart parameter_ids="4,13,14" interval="day" limit="30" height="380" title="30 days Brine & Weather" padding_bottom="120"]
```
Key attributes:
- `parameter_ids`: Comma-separated list of IDs you already collect.
- `interval`: bucket size; supports `minute`, `hour`, `day` (also numeric minute counts like `30` or `30min`).
- `limit`: number of buckets (e.g. 30 days).
- `height`: SVG height in pixels.
- `padding_bottom`: extra space for rotated X-axis labels.
- `show_legend`: `true|false` for visibility toggles.
- `energy_pair`: NEW – derive daily production/consumption deltas + ratio (only when `interval="day"`).

### 8.2 Energy Pair Examples
Add production and consumption cumulative IDs to the chart; then specify the `energy_pair` attribute:
```
[myuplink_pivot_chart parameter_ids="4,13,14,28392,28393" interval="day" limit="30" height="380" padding_bottom="120" title="30 Day Energy & Temps" energy_pair="28392,28393"]
[myuplink_pivot_chart parameter_ids="28392,28393" interval="day" limit="14" height="320" padding_bottom="110" title="14 Day Energy Ratio" energy_pair="28392,28393"]
```
This yields three extra toggle-able series:
- Energy Prod Δ (kWh)
- Energy Cons Δ (kWh)
- Prod/Cons Ratio

(First day shows null because there is no previous day to compute a delta.)

### 8.3 Simple Latest Values Table
```
[myuplink_table parameter_ids="13,14,781" title="Current Values"]
```

### 8.4 Full Recent Rows Table
```
[myuplink_table_full parameter_ids="13,14" limit="200" show_export="true" title="Recent Sensor Data"]
```
Exports a CSV when you click the button (admin-only unless you add public key logic).

### 8.5 Pivot Table (Non-Chart)
```
[myuplink_table_pivot parameter_ids="13,14" interval="hour" limit="168" title="Past Week Hourly"]
```
Builds a time × parameter matrix.

### 8.6 Single Value
```
[myuplink_value parameter_id="13" format="Brine Temp: {value} {unit}" decimals="1"]
```
Place inside inline content for dashboards.

### 8.7 Energy Summary (Tabular)
```
[myuplink_energy_summary prod_id="28392" cons_id="28393"]
```
Shows 1,2,3,7,14,30,365-day intervals + total since data began.

### 8.8 Common Timeframe Chart Examples
Below are ready-to-use shortcode examples for typical monitoring windows. Adjust `parameter_ids` to match what you collect.

#### Daily View (Last 30 Days – Daily Buckets + Energy Deltas)
```
[myuplink_pivot_chart parameter_ids="4,13,14,28392,28393" interval="day" limit="30" height="380" padding_bottom="120" title="30 Day Energy & Temps" energy_pair="28392,28393" y_ticks="6" x_ticks="10"]
```
Explanation:
- `interval="day"` uses one bucket per calendar day (UTC underlying; displayed local by default).
- `limit="30"` shows 30 buckets (roughly last 30 days).
- `energy_pair` adds 3 derived series (Production Δ, Consumption Δ, Ratio).
- Increase `padding_bottom` if labels overlap.

#### Weekly View (Past 7 Days Hourly Resolution)
```
[myuplink_pivot_chart parameter_ids="4,13,14" interval="hour" limit="168" height="360" title="Past 7 Days Hourly" y_ticks="8" x_ticks="12" padding_bottom="90"]
```
Explanation:
- 7 days × 24 hours = 168 hourly buckets.
- Good for spotting daily cycles while retaining detail.
- Remove or add IDs freely; no `energy_pair` here since deltas were limited to daily buckets in this version.

#### Recent Hours (Last 24 Hours Hourly Buckets)
```
[myuplink_pivot_chart parameter_ids="4,13,14" interval="hour" limit="24" height="320" title="Last 24 Hours" y_ticks="6" x_ticks="12" padding_bottom="70"]
```
Explanation:
- Focused short-term trend.
- Using hourly buckets avoids clutter vs. minute-level data.

#### High-Resolution (Last 12 Hours, 30-Minute Buckets)
```
[myuplink_pivot_chart parameter_ids="4,13,14" interval="30" limit="24" height="300" title="12 Hours (30m Buckets)" y_ticks="5" x_ticks="12" padding_bottom="70"]
```
Explanation:
- `interval="30"` means 30-minute buckets (numeric minutes).
- `limit="24"` → 24 × 30min = 12 hours.
- Useful when you need more granularity without going full minute.

#### Ultra-Granular (Last 3 Hours Minute Buckets)
```
[myuplink_pivot_chart parameter_ids="4,13,14" interval="minute" limit="180" height="260" title="Last 3 Hours (1m)" y_ticks="5" x_ticks="9" padding_bottom="70"]
```
Explanation:
- 180 minutes = 3 hours.
- Best for diagnosing quick changes; expect more missing buckets if sensor updates are every 30 minutes (values forward-filled).

Tips:
- If labels collide: lower `x_ticks` or raise `padding_bottom`.
- If Y numbers look crowded: lower `y_ticks` or allow plugin to auto-scale (still integer steps).
- Combine energy deltas only with `interval="day"` in this version.

---
## 9. Understanding Buckets & Intervals
The pivot chart and pivot table bucket the timeline. For each bucket point, the latest value *at or before* that timestamp is used. This means gaps are forward-filled; no statistical interpolation occurs.

Interval behavior examples:
- `interval="minute"` → 60-second buckets.
- `interval="30"` or `interval="30min"` → 30-minute buckets.
- `interval="hour"` → Hourly buckets.
- `interval="day"` → Daily buckets (used for energy deltas).

---
## 10. Energy Delta & Ratio Logic
When `energy_pair="prodID,consID"` and `interval="day"`:
1. For each consecutive day bucket, subtract previous day’s cumulative value from current.
2. If counters decrease (reset/rollover) delta becomes `null` for that day.
3. Ratio = Production Delta ÷ Consumption Delta (only when both are present and consumption > 0).
4. First day always `null` (no prior baseline).

If you need hourly energy deltas later, extend logic (currently intentionally limited to keep it simple and avoid confusion).

---
## 11. CSV Export Options
- Recent raw rows: from `[myuplink_table_full]` button.
- Pivot subset: from `[myuplink_pivot_chart]` (inside its internal table disclosure).
- Full historical pivot (streaming): shortcode `[myuplink_pivot_export_all]` (admin or API key) builds a URL that streams all pivoted buckets.

Large exports may take time—consider narrowing IDs or interval.

---
## 12. Security & Access Control
| Feature | Protection |
|---------|-----------|
| Collector REST trigger | Secret header or `?key=` parameter |
| Display REST endpoints | Optional API key or admin capability |
| CSV exports | Admin only unless API key provided |

Keep secrets private—rotate them if leaked.

---
## 13. Performance & Scalability
- Single table with indexed `ts`, `parameter_id` keeps lookups efficient.
- Storing too many parameters too frequently may grow table quickly; consider periodic archiving.
- For very large histories, rely on streaming export instead of loading huge pages.

---
## 14. Troubleshooting
| Problem | Cause | Fix |
|---------|-------|-----|
| No data appearing | Wrong API credentials or parameter IDs | Re-check credentials; use Test Connection button |
| Cron not running | Low site traffic | Set up server cron to call `wp-cron.php` |
| Repeated errors in log | API downtime / auth fail | Regenerate credentials; check MyUplink status |
| Energy deltas all null | Using interval not equal to `day` or non-cumulative IDs | Switch to `interval="day"`; confirm counters monotonic |
| Pivot chart blank | No data in selected range | Increase `limit` or verify collection timing |
| CSV export denied | No admin or missing API key | Add REST key in Display settings or log in as admin |

Enable WP debug log to inspect collector messages (they include helpful error_log output).

---
## 15. Updating Parameter IDs
1. Edit list in Collector settings and save.  
2. New parameters start collecting on next cron run (or manual test fetch).  
3. Use `[myuplink_list_parameters]` after some data populates to confirm names.

No restart required.

---
## 16. Extending the System (Optional Ideas)
- Add a second axis for ratio values in SVG (e.g., scaling 0–1 while temperatures vary widely).
- Implement hourly energy deltas.
- Add alert shortcodes (e.g., highlight if ratio < 0.85).
- Introduce retention policy (purge > 365-day raw points after archiving).

Keep extensions isolated inside the display plugin for clarity.

---
## 17. Zero-to-Data Quickstart (Checklist)
1. Activate both plugins.  
2. Enter API Client ID/Secret in Collector settings.  
3. Leave default parameter IDs (includes energy metrics).  
4. Click Test Connection. See rows appear.  
5. Create a new page “Dashboard”.  
6. Paste:
```
[myuplink_pivot_chart parameter_ids="4,13,14,28392,28393" interval="day" limit="30" height="380" padding_bottom="120" title="30 Day Energy & Temps" energy_pair="28392,28393"]
```
7. Publish page and view chart.  
8. Toggle series to inspect energy ratio.  
9. Add a single value widget:
```
[myuplink_value parameter_id="28392" format="Total Production: {value} {unit}" decimals="0"]
```
10. Export pivot CSV if needed.  

---
## 18. FAQ
**Q: Do I need WP-CLI or shell access?**  
Not required. WP Cron works with normal traffic.

**Q: Can I make charts public?**  
Set a REST key in Display settings and build public pages with shortcodes.

**Q: Are values averaged?**  
Pivot *fills forward* the last known reading; no averaging unless you use chart data aggregator logic (currently pivot chart uses raw latest per bucket).

**Q: What happens on API failure?**  
Run logs an error; previous data remains. System retries on next scheduled execution.

**Q: How are time zones handled?**  
Stored in UTC; display formatting can be `timezone="local"` (default) or `utc` in pivot chart.

---
## 19. Glossary
- **Cumulative Counter**: A value that only increases (e.g., total kWh produced). Used to compute deltas.
- **Delta**: Difference between current cumulative value and previous bucket’s value.
- **Ratio**: Production delta divided by consumption delta.
- **Bucket**: A time slot used to organize readings (e.g., one day, one hour).

---
## 20. Support & Logging
Check your server’s PHP error log for lines starting with `MyUplink Collector:` if troubleshooting. Provide those entries when asking for help.

---
## 21. License
GPL v2 or later. Free to modify and redistribute under the same license.

---
## 22. Safety Checklist Before Going Live
- [ ] API credentials stored and working.
- [ ] Secret regenerated and saved securely.
- [ ] REST key set (if public charts needed).
- [ ] Initial data confirmed in Recent Data table.
- [ ] Shortcodes tested on a draft page.
- [ ] Energy pair shows expected daily deltas.

You are ready. Enjoy monitoring your system with clear energy insights.

---
*This README is designed for complete beginners. For deeper customization (multi-axis, advanced aggregation) you can extend the Display plugin safely without altering the Collector’s stable data ingestion pipeline.*

---
## 23. Additional Graph Shortcode Examples (Copy & Paste)
Below are five ready-made pivot chart shortcode examples. Each demonstrates a different monitoring scenario. Paste them into any post or page. Adjust `parameter_ids`, `limit`, `interval`, and titles to fit your setup.

### 1. Daily Energy + Temperatures (30 Days)
```
[myuplink_pivot_chart parameter_ids="4,13,14,28392,28393" interval="day" limit="30" height="380" padding_bottom="120" title="30 Day Energy & Temps" energy_pair="28392,28393" x_ticks="10" y_ticks="6"]
```
Brief: Uses daily buckets to derive production/consumption deltas and ratio (only works because `interval="day"`). Adds ambient/temperature context (IDs 4,13,14).

### 2. Hourly Core Temperatures (Past Week)
```
[myuplink_pivot_chart parameter_ids="4,8,10,11" interval="hour" limit="168" height="340" title="Week of Core Temperatures" x_ticks="12" y_ticks="8" padding_bottom="105" timezone="utc"]
```
Brief: 168 hourly buckets = 7 days. Good for diurnal patterns. Uses UTC display for consistent cross-region comparison.

### 3. High-Resolution 30-Minute Brine vs Outdoor (12 Hours)
```
[myuplink_pivot_chart parameter_ids="13,14" interval="30" limit="24" height="300" title="12h Brine vs Return (30m)" x_ticks="12" y_ticks="6" padding_bottom="100"]
```
Brief: Numeric interval `30` creates 30‑minute buckets. 24 buckets = 12 hours. Useful for short-term diagnostics without minute noise.

### 4. Energy Ratio Focus (Last 14 Days)
```
[myuplink_pivot_chart parameter_ids="28392,28393" interval="day" limit="14" height="320" title="14 Day Energy Ratio" energy_pair="28392,28393" padding_bottom="110" y_ticks="6" show_legend="true"]
```
Brief: Minimal chart concentrating on production/consumption deltas and efficiency ratio. First day shows null deltas (no previous baseline).

### 5. Detailed Mixed Metrics (24 Hours, 10-Min Buckets)
```
[myuplink_pivot_chart parameter_ids="13,14,781" interval="10" limit="144" height="320" title="24h Detail (10m Buckets)" x_ticks="12" y_ticks="6" padding_bottom="115" show_legend="true"]
```
Brief: `interval="10"` => 10‑minute buckets. 144 buckets = 24 hours. Combines two temperatures plus a performance/aux metric (781) for fine-grained trend correlation.

#### Tips for Adapting These Examples
- Increase `padding_bottom` if X labels overlap when rotating.
- Lower `x_ticks` for dense time ranges; raise for sparse daily views.
- Only add `energy_pair` when using daily buckets with cumulative kWh counters.
- Use `timezone="utc"` for standardized logging or `local` (default) for end-user friendliness.
- Keep charts lean: too many instantaneous parameters can visually drown energy deltas – consider splitting into multiple charts.

Need more scenarios? Add a new section with similar formatting and keep explanations concise (what timeframe, what insight, why chosen interval).


