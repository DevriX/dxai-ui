import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import * as z from "zod";

import { Button } from "@/components/ui/button";
import {
  Form,
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";

const formSchema = z.object({
  name: z.string().min(2, { message: "Tell us who is asking." }),
  email: z.string().email({ message: "That address will not reach you." }),
  entities: z.coerce.number().min(1).max(40),
  notes: z.string().max(600).optional(),
});

type ContactValues = z.infer<typeof formSchema>;

const Contact = () => {
  const form = useForm<ContactValues>({
    resolver: zodResolver(formSchema),
    defaultValues: {
      name: "",
      email: "",
      notes: "",
    },
  });

  function onSubmit(values: ContactValues) {
    console.log("close request", values);
  }

  return (
    <div className="min-h-screen bg-background">
      <section className="band">
        <div className="container">
          <p className="eyebrow">Talk to us</p>
          <h1 className="mt-5 text-4xl md:text-5xl">Book a parallel close</h1>
          <p className="mt-6 max-w-measure text-lg text-muted-foreground">
            Two weeks of setup, then one close run alongside the one you already do. Nothing
            depends on us until the numbers agree.
          </p>

          <Form {...form}>
            <form onSubmit={form.handleSubmit(onSubmit)} className="mt-12 max-w-measure space-y-6">
              <FormField
                control={form.control}
                name="name"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Your name</FormLabel>
                    <FormControl>
                      <Input placeholder="Dana Whitfield" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="email"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Work email</FormLabel>
                    <FormControl>
                      <Input type="email" placeholder="dana@northwind.example" {...field} />
                    </FormControl>
                    <FormDescription>
                      We reply from a person, not a sequence.
                    </FormDescription>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="entities"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Legal entities</FormLabel>
                    <FormControl>
                      <Input type="number" min={1} max={40} placeholder="3" {...field} />
                    </FormControl>
                    <FormDescription>
                      Pricing is per entity, so this is the number that matters.
                    </FormDescription>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="notes"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>What does your close look like today?</FormLabel>
                    <FormControl>
                      <Textarea
                        placeholder="Where the days go, and who is waiting on whom."
                        {...field}
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <Button type="submit" size="lg">
                Request the walkthrough
              </Button>
            </form>
          </Form>
        </div>
      </section>
    </div>
  );
};

export default Contact;
