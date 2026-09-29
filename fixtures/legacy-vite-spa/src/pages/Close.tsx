import { useState } from "react";
import {
  CalendarDays,
  CircleCheck,
  Clock3,
  Info,
  MoreHorizontal,
  SlidersHorizontal,
} from "lucide-react";

import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from "@/components/ui/accordion";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";

const DAYS = {
  "day-1": [
    { owner: "Controller", task: "Cut-off posted, subledgers locked", icon: CircleCheck },
    { owner: "AP", task: "Accruals templated with their reversal dates", icon: Clock3 },
  ],
  "day-2": [
    { owner: "Treasury", task: "Bank and card feeds matched to the subledger", icon: CircleCheck },
    { owner: "Controller", task: "Intercompany proved on both sides", icon: Clock3 },
  ],
  "day-3": [
    { owner: "FP&A", task: "Flux commentary attached to the balance that moved", icon: CircleCheck },
    { owner: "Controller", task: "Review pack issued from the exceptions", icon: CalendarDays },
  ],
};

const EXCEPTIONS = [
  {
    id: "unmatched",
    title: "Unmatched bank lines",
    body: "Anything the feed could not tie to a subledger entry, with the account owner and the age in days.",
  },
  {
    id: "accruals",
    title: "Accruals without a reversal",
    body: "A recurring entry posted with no reversal date is the one that survives into next quarter unnoticed.",
  },
  {
    id: "variance",
    title: "Variance above threshold",
    body: "Every balance that moved more than the threshold you set, waiting for commentary.",
  },
];

const Close = () => {
  const [entity, setEntity] = useState("northwind-uk");

  return (
    <div className="min-h-screen bg-background">
      <section className="band">
        <div className="container">
          <p className="eyebrow">The close calendar</p>
          <h1 className="mt-5 text-4xl md:text-5xl">Three days, owned by name</h1>
          <p className="mt-6 max-w-measure text-lg text-muted-foreground">
            Every line has an owner and a due date before the period opens, so the review meeting
            starts from the exceptions.
          </p>

          <div className="mt-10 flex flex-wrap items-end gap-6">
            <div className="w-full max-w-xs">
              <Label htmlFor="entity">Entity</Label>
              <Select value={entity} onValueChange={setEntity}>
                <SelectTrigger id="entity" className="mt-2">
                  <SelectValue placeholder="Pick an entity" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="northwind-uk">Northwind UK</SelectItem>
                  <SelectItem value="northwind-us">Northwind US</SelectItem>
                  <SelectItem value="northwind-group">Northwind Group</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="outline" size="sm">
                  <MoreHorizontal className="h-4 w-4" />
                  Calendar actions
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="start">
                <DropdownMenuLabel>This period</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem>Export the calendar</DropdownMenuItem>
                <DropdownMenuItem>Reassign an owner</DropdownMenuItem>
                <DropdownMenuItem>Roll forward to next period</DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>

            <Popover>
              <PopoverTrigger asChild>
                <Button variant="outline" size="sm">
                  <SlidersHorizontal className="h-4 w-4" />
                  Variance threshold
                </Button>
              </PopoverTrigger>
              <PopoverContent align="start">
                <div className="grid gap-4">
                  <div className="space-y-1">
                    <h4 className="font-medium leading-none">Variance threshold</h4>
                    <p className="text-sm text-muted-foreground">
                      Balances that move more than this wait for commentary.
                    </p>
                  </div>
                  <div className="grid gap-2">
                    <Label htmlFor="threshold">Amount</Label>
                    <Input id="threshold" defaultValue="2,500" />
                  </div>
                </div>
              </PopoverContent>
            </Popover>

            <Tooltip>
              <TooltipTrigger asChild>
                <Button variant="ghost" size="sm" aria-label="How owners are assigned">
                  <Info className="h-4 w-4" />
                </Button>
              </TooltipTrigger>
              <TooltipContent>Owners come from the account map for this entity.</TooltipContent>
            </Tooltip>
          </div>

          <Tabs defaultValue="day-1" className="mt-12">
            <TabsList>
              <TabsTrigger value="day-1">Day 1</TabsTrigger>
              <TabsTrigger value="day-2">Day 2</TabsTrigger>
              <TabsTrigger value="day-3">Day 3</TabsTrigger>
            </TabsList>

            {Object.entries(DAYS).map(([day, rows]) => (
              <TabsContent key={day} value={day}>
                <Card>
                  <CardHeader>
                    <CardTitle>{day.replace("day-", "Day ")}</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <ul className="rule-list">
                      {rows.map((row) => (
                        <li key={row.task}>
                          <row.icon className="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                          <span>
                            <span className="font-mono text-xs uppercase tracking-[0.18em] text-foreground">
                              {row.owner}
                            </span>
                            <span className="mt-1 block">{row.task}</span>
                          </span>
                        </li>
                      ))}
                    </ul>
                  </CardContent>
                </Card>
              </TabsContent>
            ))}
          </Tabs>
        </div>
      </section>

      <section className="band">
        <div className="container">
          <p className="eyebrow">Exception queue</p>
          <h2 className="mt-5 text-3xl md:text-4xl">Open as many as you need</h2>
          <p className="mt-6 max-w-measure text-lg text-muted-foreground">
            The review runs down this list, so more than one of these is open at a time.
          </p>

          <Accordion type="multiple" className="mt-10 max-w-2xl">
            {EXCEPTIONS.map((item) => (
              <AccordionItem key={item.id} value={item.id}>
                <AccordionTrigger>{item.title}</AccordionTrigger>
                <AccordionContent>{item.body}</AccordionContent>
              </AccordionItem>
            ))}
          </Accordion>

          <Dialog>
            <DialogTrigger asChild>
              <Button className="mt-10" variant="accent">
                Add an exception
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Add an exception</DialogTitle>
                <DialogDescription>
                  It joins the queue for this period and is assigned to the account owner.
                </DialogDescription>
              </DialogHeader>
              <div className="grid gap-4 py-2">
                <div className="grid gap-2">
                  <Label htmlFor="exception-title">Title</Label>
                  <Input id="exception-title" placeholder="Duplicate vendor invoice" />
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="exception-account">Account</Label>
                  <Input id="exception-account" placeholder="2100 · Accounts payable" />
                </div>
              </div>
              <DialogFooter>
                <Button type="submit">Add to the queue</Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>
        </div>
      </section>
    </div>
  );
};

export default Close;
