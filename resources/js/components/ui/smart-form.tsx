import * as React from "react";
import {
  useForm,
  FormProvider,
  SubmitHandler,
  UseFormReturn,
  FieldValues,
  DefaultValues,
  UseFormProps,
} from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";

interface SmartFormProps<
  TFieldValues extends FieldValues,
  Schema extends z.ZodType<TFieldValues, z.ZodTypeDef, TFieldValues>
> {
  schema: Schema;
  defaultValues?: DefaultValues<TFieldValues>;
  onSubmit?: SubmitHandler<TFieldValues>;
  onChange?: (values: TFieldValues) => void;
  children: (methods: UseFormReturn<TFieldValues>) => React.ReactNode;
  formProps?: React.FormHTMLAttributes<HTMLFormElement>;
  formOptions?: Omit<UseFormProps<TFieldValues>, "defaultValues" | "resolver">;
}

export function SmartForm<
  TFieldValues extends FieldValues,
  Schema extends z.ZodType<TFieldValues, z.ZodTypeDef, TFieldValues>
>({
  schema,
  defaultValues,
  onSubmit,
  onChange,
  children,
  formProps,
  formOptions,
}: SmartFormProps<TFieldValues, Schema>) {
  const methods = useForm<TFieldValues>({
    resolver: zodResolver(schema),
    defaultValues,
    ...formOptions,
  });

  // Watch for changes if onChange is provided
  React.useEffect(() => {
    if (onChange) {
      const subscription = methods.watch((value) => {
        onChange(value as TFieldValues);
      });
      return () => subscription.unsubscribe();
    }
  }, [methods, onChange]);

  const handleSubmit = (e: React.FormEvent) => {
    if (onSubmit) {
      methods.handleSubmit(onSubmit)(e);
    } else {
      e.preventDefault();
    }
  };

  return (
    <FormProvider {...methods}>
      <form
        onSubmit={handleSubmit}
        {...formProps}
      >
        {children(methods)}
      </form>
    </FormProvider>
  );
}

// Export a type helper for form values based on schema
export type InferFormValues<T extends z.ZodType<unknown, z.ZodTypeDef, unknown>> = z.infer<T>; 