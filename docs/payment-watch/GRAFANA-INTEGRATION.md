# Grafana Integration for PaymentWatch

**Document Version:** 1.0
**Last Updated:** 2025-11-12
**Status:** Proposal for Future Implementation

---

## Executive Summary

This document evaluates **Grafana** as a monitoring and observability solution for PaymentWatch, analyzing the benefits and drawbacks of integrating Grafana for real-time metrics, alerting, and dashboards.

**Quick Verdict:**
- ✅ **Recommended for Production** - If you need advanced monitoring and alerting
- ⚠️ **Overkill for Small Teams** - Consider simpler solutions if < 10 PaymentWatch requests/day
- 🔄 **Alternative:** Start with basic logging, add Grafana when scaling

---

## Table of Contents

1. [What is Grafana?](#what-is-grafana)
2. [PaymentWatch Monitoring Use Cases](#paymentwatch-monitoring-use-cases)
3. [Pros of Using Grafana](#pros-of-using-grafana)
4. [Cons of Using Grafana](#cons-of-using-grafana)
5. [Implementation Architecture](#implementation-architecture)
6. [Metrics to Track](#metrics-to-track)
7. [Sample Grafana Dashboard](#sample-grafana-dashboard)
8. [Alternatives to Grafana](#alternatives-to-grafana)
9. [Cost Analysis](#cost-analysis)
10. [Recommendations](#recommendations)

---

## What is Grafana?

**Grafana** is an open-source analytics and monitoring platform that provides:
- **Real-time dashboards** - Visualize metrics with graphs, tables, and charts
- **Alerting** - Get notified when metrics cross thresholds
- **Multi-datasource** - Connect to Prometheus, MySQL, Elasticsearch, etc.
- **Custom queries** - Build complex queries for deep insights

**Common Use Cases:**
- Application performance monitoring (APM)
- Infrastructure monitoring (servers, databases)
- Business metrics tracking
- Log aggregation and analysis

---

## PaymentWatch Monitoring Use Cases

### What Should We Monitor?

#### 1. Performance Metrics
- **Response time** (average, P50, P95, P99)
- **Throughput** (requests per second)
- **Error rate** (% of failed requests)
- **Database query time**

#### 2. Usage Metrics
- **Total requests per day/hour**
- **Requests by operator** (==, LIKE, etc.)
- **Requests by table** (oxorder, oepaypal_order, etc.)
- **Active API keys**

#### 3. Security Metrics
- **Authentication failures** (invalid API keys, wrong IPs)
- **SQL injection attempts** (blocked malicious queries)
- **Rate limit violations**
- **Suspicious patterns** (repeated failures from same IP)

#### 4. Business Metrics
- **Test success rate** (% of passing assumptions)
- **Average wait time** (for waitFor() calls)
- **Timeout frequency** (how often tests timeout)
- **Cache hit rate** (if caching is enabled)

---

## Pros of Using Grafana

### ✅ 1. Real-Time Visibility

**Benefit:** See PaymentWatch performance in real-time.

**Example Use Case:**
- Dashboard shows sudden spike in response time
- Team investigates and finds missing database index
- Issue fixed before affecting production tests

**Without Grafana:**
- Issue discovered only when tests start failing
- Manual log analysis required
- Longer time to resolution

---

### ✅ 2. Historical Trend Analysis

**Benefit:** Track performance over time to identify patterns.

**Example Insights:**
```
Week 1: Average response time = 15ms
Week 2: Average response time = 25ms (+67%)
Week 3: Average response time = 45ms (+80%)
Week 4: Average response time = 120ms (+167%)
```

**Action:** Investigate what changed between Week 1 and Week 2.

**Discovery:** Database table grew from 1,000 to 100,000 orders; missing index.

---

### ✅ 3. Proactive Alerting

**Benefit:** Get notified before problems become critical.

**Example Alerts:**

**Alert 1: Slow Queries**
```yaml
Alert: PaymentWatch Slow Query
Condition: avg(response_time) > 100ms for 5 minutes
Action: Send Slack notification to #platform-team
Message: "⚠️ PaymentWatch response time exceeded 100ms"
```

**Alert 2: High Error Rate**
```yaml
Alert: PaymentWatch Error Spike
Condition: error_rate > 5% for 2 minutes
Action: Send PagerDuty alert
Message: "🚨 PaymentWatch error rate at 8% - investigate immediately"
```

**Alert 3: Authentication Attacks**
```yaml
Alert: Potential Security Attack
Condition: auth_failures > 50 in 1 minute
Action: Send email to security@company.com
Message: "🔒 High number of authentication failures detected"
```

---

### ✅ 4. Multi-Datasource Integration

**Benefit:** Correlate PaymentWatch metrics with other systems.

**Example Dashboard - Holistic View:**

```
+----------------------------------------------------------+
|  PaymentWatch Performance Dashboard                      |
+----------------------------------------------------------+
| PaymentWatch Response Time  |  Database CPU Usage        |
| (from Prometheus)           |  (from MySQL exporter)     |
|                             |                            |
| ▁▂▃▅▇ 45ms avg             | ▂▃▄▅▆ 65% avg             |
+----------------------------------------------------------+
| Test Success Rate           |  Application Errors        |
| (from PaymentWatch)         |  (from Sentry)             |
|                             |                            |
| ████████░░ 85%             | ▁▁▂▁▁ 3 errors/hour       |
+----------------------------------------------------------+
```

**Insight:** When database CPU spikes, PaymentWatch response time increases, causing test failures.

---

### ✅ 5. Custom Dashboards for Different Teams

**Benefit:** Each team gets relevant metrics.

**QA Team Dashboard:**
- Test success rate by framework (Playwright, Cypress, Jest)
- Most common timeout scenarios
- Flaky test detection (tests that intermittently fail)

**DevOps Team Dashboard:**
- Infrastructure health (CPU, memory, disk I/O)
- Request rate and throughput
- Error logs and stack traces

**Security Team Dashboard:**
- Authentication failures by IP
- SQL injection attempts
- Unusual access patterns

---

### ✅ 6. Open Source & Extensible

**Benefit:** Free to use, highly customizable.

**Extensions Available:**
- 100+ data source plugins
- 1000+ dashboard templates
- Custom panel plugins
- REST API for automation

**Example - Custom Panel:**
Create a custom panel showing PaymentWatch "health score":
```javascript
Health Score = (
  (1 - error_rate) * 0.4 +
  (response_time < 50ms ? 1 : 0.5) * 0.3 +
  (cache_hit_rate) * 0.2 +
  (test_success_rate) * 0.1
) * 100
```

---

### ✅ 7. Industry Standard

**Benefit:** Team likely already familiar with Grafana.

**Statistics:**
- Used by 1M+ companies worldwide
- Standard tool in DevOps ecosystem
- Large community and documentation

---

## Cons of Using Grafana

### ❌ 1. Additional Infrastructure Complexity

**Issue:** Grafana requires setup and maintenance.

**Required Components:**
```
┌─────────────┐
│ PaymentWatch│
└──────┬──────┘
       │ metrics
       ↓
┌─────────────┐
│ Prometheus  │  ← Scrapes metrics every 15s
└──────┬──────┘
       │ query
       ↓
┌─────────────┐
│  Grafana    │  ← Visualizes data
└─────────────┘
```

**Maintenance Overhead:**
- Install and configure Prometheus
- Install and configure Grafana
- Set up data retention policies
- Manage disk space for time-series data
- Keep software updated
- Configure backups

**Team Impact:**
- Requires DevOps knowledge
- Adds ~2-4 hours/month maintenance time

---

### ❌ 2. Learning Curve

**Issue:** Team needs to learn Grafana-specific concepts.

**Skills Required:**
- PromQL (Prometheus Query Language)
- Grafana dashboard creation
- Alert rule configuration
- Data source setup

**Example PromQL Query (Not Intuitive):**
```promql
# Calculate P95 response time
histogram_quantile(0.95,
  sum(rate(paymentwatch_response_time_bucket[5m])) by (le)
)
```

**Training Time:** ~1 week for basic proficiency

---

### ❌ 3. Overkill for Small Projects

**Issue:** Not worth it if PaymentWatch usage is low.

**When Grafana is Overkill:**
- < 100 PaymentWatch requests/day
- Only 1-2 developers running E2E tests
- No dedicated DevOps team
- Budget constraints

**Better Alternative for Small Projects:**
- Simple log files with grep
- Basic database queries
- Email alerts from cron jobs

**Example - Simple Monitoring (No Grafana):**
```bash
# Check error rate (every 5 minutes via cron)
#!/bin/bash
ERROR_COUNT=$(grep "ERROR" /var/log/paymentwatch.log | wc -l)
if [ $ERROR_COUNT -gt 10 ]; then
  echo "High error rate: $ERROR_COUNT errors" | mail -s "PaymentWatch Alert" team@company.com
fi
```

---

### ❌ 4. Data Retention Costs

**Issue:** Storing metrics long-term requires disk space.

**Example Storage Requirements:**

| Retention Period | Metrics Collected | Storage Needed |
|------------------|-------------------|----------------|
| 7 days           | 10 metrics @ 15s  | ~500 MB        |
| 30 days          | 10 metrics @ 15s  | ~2 GB          |
| 365 days         | 10 metrics @ 15s  | ~25 GB         |

**Cost Impact:**
- Cloud storage: $0.10/GB/month = $2.50/month (30 days retention)
- Backups: +50% = $3.75/month total

**Note:** Not expensive, but adds up with multiple services.

---

### ❌ 5. Prometheus Instrumentation Required

**Issue:** Must add Prometheus metrics to PaymentWatch code.

**Code Changes Needed:**

**Before (No Metrics):**
```php
public function assume(Request $request): JsonResponse
{
    // ... handle request ...
    return new JsonResponse(['success' => true]);
}
```

**After (With Metrics):**
```php
use Prometheus\CollectorRegistry;

public function assume(Request $request): JsonResponse
{
    $startTime = microtime(true);

    // ... handle request ...

    $duration = microtime(true) - $startTime;

    // Record metric
    $this->registry->getOrRegisterHistogram(
        'paymentwatch',
        'response_time_seconds',
        'Response time in seconds',
        ['endpoint', 'status']
    )->observe($duration, ['/assume', '200']);

    return new JsonResponse(['success' => true]);
}
```

**Development Time:** ~2-4 hours to add metrics throughout codebase

---

### ❌ 6. Alert Fatigue Risk

**Issue:** Too many alerts = ignored alerts.

**Common Problem:**
```
09:00 - Alert: Response time > 100ms
09:15 - Alert: Response time > 100ms
09:30 - Alert: Response time > 100ms
...
[Team ignores alerts]
...
14:00 - Critical: PaymentWatch completely down
[No one notices because they stopped checking alerts]
```

**Mitigation Required:**
- Careful alert threshold tuning
- Alert aggregation rules
- Alert escalation policies
- Regular alert review meetings

---

### ❌ 7. Single Point of Failure

**Issue:** If Grafana goes down, you lose monitoring visibility.

**Scenario:**
1. Grafana server crashes
2. Team has no visibility into PaymentWatch health
3. PaymentWatch could be failing but no one knows

**Mitigation:**
- High availability Grafana setup (complex)
- Backup monitoring solution (e.g., basic logging)
- Health checks independent of Grafana

---

## Implementation Architecture

### Recommended Architecture

```
┌─────────────────────────────────────────────────────────┐
│                    E2E Test Runners                     │
│            (Playwright, Cypress, Jest)                  │
└───────────────────────┬─────────────────────────────────┘
                        │ HTTP requests
                        ↓
┌─────────────────────────────────────────────────────────┐
│                  PaymentWatch API                       │
│                                                          │
│  ┌──────────────────────────────────────────────────┐  │
│  │  AssumptionController                            │  │
│  │    ↓                                              │  │
│  │  MetricsCollector ← Records metrics              │  │
│  │    ↓                                              │  │
│  │  Exports metrics at /metrics endpoint            │  │
│  └──────────────────────────────────────────────────┘  │
└───────────────────────┬─────────────────────────────────┘
                        │ /metrics endpoint (Prometheus format)
                        ↓
┌─────────────────────────────────────────────────────────┐
│                   Prometheus Server                     │
│                                                          │
│  - Scrapes /metrics every 15s                          │
│  - Stores time-series data                             │
│  - Retention: 30 days                                   │
└───────────────────────┬─────────────────────────────────┘
                        │ PromQL queries
                        ↓
┌─────────────────────────────────────────────────────────┐
│                   Grafana Server                        │
│                                                          │
│  - Dashboards for visualization                        │
│  - Alert rules and notifications                       │
│  - User access control                                  │
└─────────────────────────────────────────────────────────┘
```

### Docker Compose Setup

```yaml
version: '3.8'

services:
  paymentwatch:
    image: oxid-esales/payment-component
    ports:
      - "80:80"
    environment:
      - PAYMENTWATCH_METRICS_ENABLED=true

  prometheus:
    image: prom/prometheus:latest
    ports:
      - "9090:9090"
    volumes:
      - ./prometheus.yml:/etc/prometheus/prometheus.yml
      - prometheus-data:/prometheus
    command:
      - '--config.file=/etc/prometheus/prometheus.yml'
      - '--storage.tsdb.retention.time=30d'

  grafana:
    image: grafana/grafana:latest
    ports:
      - "3000:3000"
    environment:
      - GF_SECURITY_ADMIN_PASSWORD=admin
    volumes:
      - grafana-data:/var/lib/grafana
    depends_on:
      - prometheus

volumes:
  prometheus-data:
  grafana-data:
```

---

## Metrics to Track

### Core PaymentWatch Metrics

```yaml
# Prometheus metrics configuration

# 1. Request Counter
paymentwatch_requests_total{method="POST",endpoint="/assume",status="200"}

# 2. Response Time Histogram
paymentwatch_response_time_seconds_bucket{endpoint="/assume",le="0.05"}
paymentwatch_response_time_seconds_bucket{endpoint="/assume",le="0.1"}
paymentwatch_response_time_seconds_bucket{endpoint="/assume",le="0.5"}

# 3. Error Counter
paymentwatch_errors_total{type="authentication",status="401"}
paymentwatch_errors_total{type="validation",status="400"}
paymentwatch_errors_total{type="server",status="500"}

# 4. Operator Usage
paymentwatch_operator_usage_total{operator="=="}
paymentwatch_operator_usage_total{operator="LIKE"}
paymentwatch_operator_usage_total{operator="IN"}

# 5. Database Query Time
paymentwatch_db_query_seconds{table="oxorder"}
paymentwatch_db_query_seconds{table="oepaypal_order"}

# 6. Cache Metrics (if enabled)
paymentwatch_cache_hits_total
paymentwatch_cache_misses_total

# 7. Assumption Results
paymentwatch_assumptions_total{result="passed"}
paymentwatch_assumptions_total{result="failed"}

# 8. Timeout Events
paymentwatch_timeouts_total{reason="database"}
paymentwatch_timeouts_total{reason="network"}
```

---

## Sample Grafana Dashboard

### Dashboard Layout

```
╔══════════════════════════════════════════════════════════╗
║              PaymentWatch Monitoring                     ║
╠══════════════════════════════════════════════════════════╣
║                                                          ║
║  ┌────────────────┐  ┌────────────────┐  ┌───────────┐ ║
║  │ Total Requests │  │  Error Rate    │  │ Avg Time  │ ║
║  │   1,234/hour   │  │     1.2%       │  │   25ms    │ ║
║  └────────────────┘  └────────────────┘  └───────────┘ ║
║                                                          ║
║  ┌──────────────────────────────────────────────────┐  ║
║  │  Response Time (P50, P95, P99)                   │  ║
║  │                                                   │  ║
║  │   P99 ────────────────────── 95ms               │  ║
║  │   P95 ──────────────── 45ms                     │  ║
║  │   P50 ────── 18ms                               │  ║
║  │   ▁▂▃▄▅▆▇█▆▅▄▃▂▁▂▃▄▅▆▇                        │  ║
║  └──────────────────────────────────────────────────┘  ║
║                                                          ║
║  ┌─────────────────────┐  ┌─────────────────────────┐  ║
║  │ Requests by Operator│  │ Requests by Table       │  ║
║  │                     │  │                         │  ║
║  │  ==      55%  ████  │  │  oxorder        70%  ██│  ║
║  │  LIKE    20%  ██    │  │  oepaypal_order 25%  █ │  ║
║  │  >       15%  █     │  │  oxuser          5%     │  ║
║  │  IN      10%  █     │  │                         │  ║
║  └─────────────────────┘  └─────────────────────────┘  ║
║                                                          ║
║  ┌──────────────────────────────────────────────────┐  ║
║  │  Error Rate Over Time                            │  ║
║  │                                                   │  ║
║  │  Auth Errors    ▁▁▂▃▂▁▁                         │  ║
║  │  Validation     ▁▁▁▁▁▁▁                         │  ║
║  │  Server Errors  ▁▁▁▂▁▁▁                         │  ║
║  └──────────────────────────────────────────────────┘  ║
║                                                          ║
║  ┌──────────────────────────────────────────────────┐  ║
║  │  Top 10 Slowest Tables                           │  ║
║  │                                                   │  ║
║  │  1. oxarticles              145ms  ██████        │  ║
║  │  2. oxorder                  45ms  ██            │  ║
║  │  3. oepaypal_order           28ms  █            │  ║
║  └──────────────────────────────────────────────────┘  ║
╚══════════════════════════════════════════════════════════╝
```

### Grafana JSON Dashboard (Import)

```json
{
  "dashboard": {
    "title": "PaymentWatch Monitoring",
    "panels": [
      {
        "id": 1,
        "title": "Total Requests",
        "type": "stat",
        "targets": [
          {
            "expr": "rate(paymentwatch_requests_total[5m]) * 3600"
          }
        ]
      },
      {
        "id": 2,
        "title": "Response Time (P95)",
        "type": "graph",
        "targets": [
          {
            "expr": "histogram_quantile(0.95, sum(rate(paymentwatch_response_time_seconds_bucket[5m])) by (le))"
          }
        ]
      }
    ]
  }
}
```

---

## Alternatives to Grafana

### 1. ELK Stack (Elasticsearch, Logstash, Kibana)

**Pros:**
- Better for log aggregation
- Full-text search capabilities
- Good for debugging specific requests

**Cons:**
- More resource-intensive than Grafana
- Higher learning curve
- More expensive to run

**Use Case:** If you need detailed log analysis more than metrics.

---

### 2. Datadog

**Pros:**
- Fully managed (no setup required)
- Excellent UI/UX
- Built-in APM and tracing
- Great support

**Cons:**
- **Expensive** ($15-31/host/month)
- Vendor lock-in
- Data sent to third-party

**Use Case:** If budget allows and you want turnkey solution.

---

### 3. New Relic

**Pros:**
- Easy to set up
- AI-powered insights
- Good mobile app

**Cons:**
- **Very expensive** ($25-99/user/month for full platform)
- Can be overkill for simple monitoring

**Use Case:** Enterprise teams with budget.

---

### 4. Simple File Logging + Cron

**Pros:**
- Zero setup complexity
- No additional infrastructure
- Free

**Cons:**
- Manual analysis required
- No real-time dashboards
- No proactive alerting

**Use Case:** Small projects, low traffic.

**Example:**
```bash
# Daily report via cron
0 9 * * * /usr/local/bin/paymentwatch-report.sh | mail -s "PaymentWatch Daily Report" team@company.com
```

---

### 5. Cloud Provider Native Tools

**AWS CloudWatch / GCP Cloud Monitoring / Azure Monitor**

**Pros:**
- Already available if using cloud provider
- Integrated with cloud infrastructure
- No extra servers to manage

**Cons:**
- Vendor-specific
- Limited customization
- Querying can be clunky

**Use Case:** If already heavily invested in one cloud provider.

---

## Cost Analysis

### Grafana Total Cost of Ownership (TCO)

#### Setup Costs (One-Time)
| Item | Time | Hourly Rate | Cost |
|------|------|-------------|------|
| Install Prometheus | 2 hours | $100 | $200 |
| Install Grafana | 1 hour | $100 | $100 |
| Add metrics to code | 4 hours | $100 | $400 |
| Create dashboards | 3 hours | $100 | $300 |
| Set up alerts | 2 hours | $100 | $200 |
| **Total Setup** | **12 hours** | | **$1,200** |

#### Monthly Operating Costs
| Item | Cost |
|------|------|
| Server (2 CPU, 4GB RAM) | $20/month |
| Storage (25GB @ $0.10/GB) | $2.50/month |
| Maintenance (2 hours/month @ $100/hr) | $200/month |
| **Total Monthly** | **$222.50/month** |

#### Annual TCO
```
Year 1: $1,200 (setup) + $222.50 * 12 (monthly) = $3,870
Year 2+: $222.50 * 12 = $2,670/year
```

### Alternative: Datadog Cost

```
3 hosts * $31/host/month = $93/month = $1,116/year

Savings: $3,870 - $1,116 = $2,754 first year
But: Vendor lock-in, data privacy concerns
```

---

## Recommendations

### ✅ Use Grafana If:

1. **High PaymentWatch Usage**
   - > 1,000 requests/day
   - Multiple teams using PaymentWatch
   - Running 24/7 E2E test suites

2. **Dedicated DevOps Team**
   - Team has Prometheus/Grafana expertise
   - Infrastructure management capacity available

3. **Compliance Requirements**
   - Need audit trails for monitoring
   - Must prove system reliability (SLAs)

4. **Scaling Concerns**
   - Expect rapid growth in PaymentWatch usage
   - Need to identify bottlenecks proactively

5. **Complex Environment**
   - Multiple environments (dev, staging, prod)
   - Multiple OXID shops using PaymentWatch

---

### ❌ Skip Grafana If:

1. **Low Usage**
   - < 100 requests/day
   - Only 1-2 developers using PaymentWatch

2. **No DevOps Resources**
   - Small team without infrastructure expertise
   - No time for setup and maintenance

3. **Budget Constraints**
   - Cannot afford $3,870 first year cost
   - Better to invest in other priorities

4. **Simple Needs**
   - Just need to know "is it working?"
   - Email alerts for critical failures sufficient

---

### 🔄 Phased Approach (Recommended)

**Phase 1: Basic Logging (Weeks 1-4)**
- Simple file logging
- Daily email reports via cron
- Manual log analysis when issues occur

**Phase 2: Basic Metrics (Months 2-3)**
- Add Prometheus metrics to code
- Simple Grafana dashboard (response time, error rate)
- 1-2 critical alerts

**Phase 3: Advanced Monitoring (Month 4+)**
- Comprehensive dashboards
- Full alert coverage
- Historical trend analysis

**Benefit:** Spread costs over time, validate ROI before full investment.

---

## Conclusion

### Final Verdict

| Project Size | Recommendation | Rationale |
|--------------|----------------|-----------|
| **Small** (< 100 req/day) | ❌ **Skip Grafana** | Use simple logging + email alerts |
| **Medium** (100-1000 req/day) | ⚠️ **Consider** | Evaluate cost vs. benefit for your team |
| **Large** (> 1000 req/day) | ✅ **Use Grafana** | Essential for reliability at scale |
| **Enterprise** (> 10K req/day) | ✅ **Must Have** | Critical for operations + compliance |

### Key Takeaway

**Grafana is a powerful tool, but it's not free (in terms of time and complexity).**

Ask yourself:
1. Do we **need** real-time dashboards, or would daily reports suffice?
2. Do we have the team capacity to maintain Prometheus + Grafana?
3. Will the insights from Grafana lead to actionable improvements?

If you answered "yes" to all three → **Use Grafana**
If you answered "no" to any → **Start simpler, add Grafana later**

---

## Next Steps

### If You Decide to Use Grafana

1. **Read:** [Official Grafana Documentation](https://grafana.com/docs/)
2. **Follow:** [Sprint 10 - Production Release](implementation/sprint-10-release.md) for monitoring setup
3. **Install:** Use Docker Compose setup provided in this document
4. **Start Small:** One dashboard, 2-3 metrics, 1 alert
5. **Iterate:** Add more metrics/alerts based on real needs

### If You Skip Grafana

1. **Set up basic logging** in `src/Watch/Infrastructure/AuditLogger.php`
2. **Create daily report script** (see examples in this document)
3. **Monitor error logs** for critical issues
4. **Revisit decision** in 3-6 months as usage grows

---

**Document prepared by:** PaymentWatch Team
**For questions:** Create issue at https://github.com/OXID-eSales/payment-component/issues

---

**Happy Monitoring! 📊**
