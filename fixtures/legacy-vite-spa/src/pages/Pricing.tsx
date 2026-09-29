import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";

const TIERS = [
  { name: "Single entity", price: "$740", note: "per month, one legal entity", cta: "default" as const },
  { name: "Consolidated", price: "$2,150", note: "per month, up to eight entities", cta: "accent" as const },
];

const Pricing = () => (
  <div className="min-h-screen bg-background">
    <section className="band">
      <div className="container">
        <p className="eyebrow">Pricing</p>
        <h1 className="mt-5 text-4xl md:text-5xl">Priced per entity, not per seat</h1>
        <p className="mt-6 max-w-measure text-lg text-muted-foreground">
          Everyone who touches the close needs an account, so charging for seats only teaches teams to
          share logins.
        </p>
        <div className="mt-12 grid gap-6 sm:grid-cols-2">
          {TIERS.map((tier) => (
            <Card key={tier.name}>
              <CardHeader>
                <CardTitle>{tier.name}</CardTitle>
                <p className="font-display text-4xl text-primary">{tier.price}</p>
              </CardHeader>
              <CardContent>
                <p>{tier.note}</p>
                <Button variant={tier.cta} className="mt-6 w-full">
                  Talk to us
                </Button>
              </CardContent>
            </Card>
          ))}
        </div>
      </div>
    </section>
  </div>
);

export default Pricing;
