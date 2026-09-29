import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from "@/components/ui/accordion";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { ArrowRight, CheckCircle2, Clock, FileSpreadsheet } from "lucide-react";
import ledgerMark from "@/assets/ledger-mark.svg";

const STATS = [
  { figure: "11.4", unit: "days", label: "Median close before Northwind" },
  { figure: "3.2", unit: "days", label: "Median close after one quarter" },
  { figure: "94", unit: "%", label: "Reconciliations matched on first pass" },
];

const PILLARS = [
  {
    icon: FileSpreadsheet,
    title: "One schedule, not nine spreadsheets",
    body: "Every account carries its own owner, due date and supporting tie-out. The close calendar is the system of record, not a shared workbook somebody forked in March.",
  },
  {
    icon: Clock,
    title: "Accruals that post themselves",
    body: "Recurring entries are templated with a reversal date at creation. Nothing waits on a controller remembering which entry needs backing out in the first week.",
  },
  {
    icon: CheckCircle2,
    title: "Variance explained before review",
    body: "Flux commentary is attached to the balance that moved, at the threshold you set, so the review meeting starts from the exceptions instead of discovering them.",
  },
];

const CHECKS = [
  "Bank and credit card feeds matched to the subledger nightly",
  "Intercompany eliminations proved on both sides before consolidation",
  "Prepaid and fixed asset schedules rolled forward without re-keying",
  "Audit-ready support packaged per account, per period",
];

const FAQ = [
  {
    q: "Does this replace our general ledger?",
    a: "No. Northwind sits on top of the ledger you already run and reads it. The close workflow, the tie-outs and the evidence live here; the journal entries still post where they always did.",
  },
  {
    q: "How long does implementation take?",
    a: "Two weeks for a single entity, four to six for a consolidated group. The work is mostly mapping your chart of accounts and deciding who owns each reconciliation.",
  },
  {
    q: "What happens in the first close?",
    a: "We run it alongside your existing process rather than instead of it. You get a parallel result to compare against, and nothing depends on the new system until the numbers agree.",
  },
];

const Index = () => {
  return (
    <div className="min-h-screen bg-background">
      <header className="border-b border-border">
        <div className="container flex h-20 items-center justify-between">
          <a href="/" className="flex items-center gap-3">
            <img src={ledgerMark} alt="Northwind Ledger" className="h-8 w-28" />
          </a>
          <nav className="hidden items-center gap-8 text-sm md:flex">
            <a href="#pillars" className="text-muted-foreground transition-colors hover:text-foreground">
              Product
            </a>
            <a href="#proof" className="text-muted-foreground transition-colors hover:text-foreground">
              Proof
            </a>
            <a href="/pricing" className="text-muted-foreground transition-colors hover:text-foreground">
              Pricing
            </a>
          </nav>
          <Button size="sm" variant="outline">
            Book a walkthrough
          </Button>
        </div>
      </header>

      <section className="band">
        <div className="container">
          <p className="eyebrow">Month-end close</p>
          <h1 className="mt-5 max-w-3xl text-4xl leading-[1.08] md:text-6xl">
            Close the books in days, not weeks.
          </h1>
          <p className="mt-6 max-w-measure text-lg text-muted-foreground">
            Northwind Ledger turns the close from a fortnight of chasing tie-outs into a schedule that
            finishes on a date you can put in a board pack.
          </p>
          <div className="mt-9 flex flex-wrap items-center gap-4 animate-rise-in">
            <Button size="lg">
              Start a parallel close
              <ArrowRight className="h-4 w-4" />
            </Button>
            <Button size="lg" variant="ghost">
              See the close calendar
            </Button>
          </div>
        </div>
      </section>

      <section className="band bg-secondary/40" id="proof">
        <div className="container grid gap-10 sm:grid-cols-3">
          {STATS.map((stat) => (
            <div key={stat.label}>
              <p className="font-display text-5xl text-primary">
                {stat.figure}
                <span className="ml-1 text-2xl text-accent">{stat.unit}</span>
              </p>
              <p className="mt-3 text-sm text-muted-foreground">{stat.label}</p>
            </div>
          ))}
        </div>
      </section>

      <section className="band" id="pillars">
        <div className="container">
          <p className="eyebrow">What changes</p>
          <h2 className="mt-5 text-3xl md:text-4xl">Three things stop being manual</h2>
          <div className="mt-12 grid gap-6 md:grid-cols-3">
            {PILLARS.map((pillar) => (
              <Card key={pillar.title}>
                <CardHeader>
                  <pillar.icon className="h-6 w-6 text-primary" />
                  <CardTitle>{pillar.title}</CardTitle>
                </CardHeader>
                <CardContent>{pillar.body}</CardContent>
              </Card>
            ))}
          </div>
        </div>
      </section>

      <section className="band bg-ledger-ink text-ledger-sage">
        <div className="container grid gap-12 md:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
          <div>
            <p className="font-mono text-xs uppercase tracking-[0.18em] text-ledger-sage/70">Included</p>
            <h2 className="mt-5 text-3xl text-ledger-sage md:text-4xl">
              The unglamorous parts, handled
            </h2>
          </div>
          <ul className="rule-list">
            {CHECKS.map((check) => (
              <li key={check} className="border-ledger-sage/20 text-ledger-sage/80">
                <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-ledger-clay" />
                <span>{check}</span>
              </li>
            ))}
          </ul>
        </div>
      </section>

      <section className="band">
        <div className="container">
          <p className="eyebrow">Questions</p>
          <h2 className="mt-5 text-3xl md:text-4xl">Before you ask finance to switch</h2>
          <Accordion type="single" collapsible className="mt-10 max-w-2xl">
            {FAQ.map((item) => (
              <AccordionItem key={item.q} value={item.q}>
                <AccordionTrigger>{item.q}</AccordionTrigger>
                <AccordionContent>{item.a}</AccordionContent>
              </AccordionItem>
            ))}
          </Accordion>
        </div>
      </section>

      <footer className="bg-card">
        <div className="container flex flex-col gap-4 py-12 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
          <p>Northwind Ledger, Inc.</p>
          <p className="font-mono text-xs">Built for controllers who would rather not work weekends.</p>
        </div>
      </footer>
    </div>
  );
};

export default Index;
