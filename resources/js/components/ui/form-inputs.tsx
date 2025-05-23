import * as React from "react";
import { useFormContext, FieldPath, FieldValues } from "react-hook-form";
import { FormControl, FormField, FormItem, FormLabel, FormMessage } from "@/components/ui/form";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { DateRangePicker } from "./date-range-picker";

// Text Input
interface TextInputProps<TFieldValues extends FieldValues> {
  name: FieldPath<TFieldValues>;
  label?: string;
  placeholder?: string;
  type?: React.InputHTMLAttributes<HTMLInputElement>["type"];
  description?: string;
  required?: boolean;
  disabled?: boolean;
}

export function TextInput<TFieldValues extends FieldValues>({
  name,
  label,
  placeholder,
  type = "text",
  description,
  required,
  disabled,
}: TextInputProps<TFieldValues>) {
  const { control } = useFormContext<TFieldValues>();

  return (
    <FormField
      control={control}
      name={name}
      render={({ field }) => (
        <FormItem>
          
          {label&&(<FormLabel className="text-sm text-muted-foreground font-semibold">{label}{required && <span className="text-destructive ml-1">*</span>}</FormLabel>)}
          <FormControl>
            <Input 
              {...field} 
              type={type} 
              placeholder={placeholder} 
              value={field.value || ""} 
              disabled={disabled}
            />
          </FormControl>
          {description && <p className="text-sm text-muted-foreground">{description}</p>}
          <FormMessage />
        </FormItem>
      )}
    />
  );
}

// Textarea Input
interface TextareaInputProps<TFieldValues extends FieldValues> {
  name: FieldPath<TFieldValues>;
  label: string;
  placeholder?: string;
  description?: string;
  required?: boolean;
  disabled?: boolean;
  rows?: number;
}

export function TextareaInput<TFieldValues extends FieldValues>({
  name,
  label,
  placeholder,
  description,
  required,
  disabled,
  rows,
}: TextareaInputProps<TFieldValues>) {
  const { control } = useFormContext<TFieldValues>();

  return (
    <FormField
      control={control}
      name={name}
      render={({ field }) => (
        <FormItem>
          <FormLabel>{label}{required && <span className="text-destructive ml-1">*</span>}</FormLabel>
          <FormControl>
            <Textarea 
              {...field} 
              placeholder={placeholder} 
              value={field.value || ""} 
              disabled={disabled}
              rows={rows}
            />
          </FormControl>
          {description && <p className="text-sm text-muted-foreground">{description}</p>}
          <FormMessage />
        </FormItem>
      )}
    />
  );
}

// Select Input
interface SelectOption {
  value: string;
  label: string;
}

interface SelectInputProps<TFieldValues extends FieldValues> {
  name: FieldPath<TFieldValues>;
  label?: string;
  options: SelectOption[];
  placeholder?: string;
  description?: string;
  required?: boolean;
  disabled?: boolean;
}

export function SelectInput<TFieldValues extends FieldValues>({
  name,
  label,
  options,
  placeholder = "Select an option",
  description,
  required,
  disabled,
}: SelectInputProps<TFieldValues>) {
  const { control } = useFormContext<TFieldValues>();

  return (
    <FormField
      control={control}
      name={name}
      render={({ field }) => (
        <FormItem>
          {label&&(<FormLabel className="text-sm text-muted-foreground font-semibold">{label}{required && <span className="text-destructive ml-1">*</span>}</FormLabel>)}
          <Select
            onValueChange={field.onChange}
            defaultValue={field.value}
            disabled={disabled}
          >
            <FormControl>
              <SelectTrigger>
                <SelectValue placeholder={placeholder} />
              </SelectTrigger>
            </FormControl>
            <SelectContent>
              {options.map((option) => (
                <SelectItem key={option.value} value={option.value}>
                  {option.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          {description && <p className="text-sm text-muted-foreground">{description}</p>}
          <FormMessage />
        </FormItem>
      )}
    />
  );
}

// Checkbox Input
interface CheckboxInputProps<TFieldValues extends FieldValues> {
  name: FieldPath<TFieldValues>;
  label: string;
  description?: string;
  disabled?: boolean;
}

export function CheckboxInput<TFieldValues extends FieldValues>({
  name,
  label,
  description,
  disabled,
}: CheckboxInputProps<TFieldValues>) {
  const { control } = useFormContext<TFieldValues>();

  return (
    <FormField
      control={control}
      name={name}
      render={({ field }) => (
        <FormItem className="flex flex-row items-start space-x-3 space-y-0 rounded-md p-2">
          <FormControl>
            <Checkbox
              checked={field.value}
              onCheckedChange={field.onChange}
              disabled={disabled}
            />
          </FormControl>
          <div className="space-y-1 leading-none">
            <FormLabel>{label}</FormLabel>
            {description && <p className="text-sm text-muted-foreground">{description}</p>}
          </div>
          <FormMessage />
        </FormItem>
      )}
    />
  );
}

// Color Input
interface ColorInputProps<TFieldValues extends FieldValues> {
  name: FieldPath<TFieldValues>;
  label: string;
  description?: string;
  required?: boolean;
  disabled?: boolean;
  showHexInput?: boolean;
}

export function ColorInput<TFieldValues extends FieldValues>({
  name,
  label,
  description,
  required,
  disabled,
  showHexInput = true,
}: ColorInputProps<TFieldValues>) {
  const { control } = useFormContext<TFieldValues>();

  return (
    <FormField
      control={control}
      name={name}
      render={({ field }) => (
        <FormItem>
          <FormLabel>{label}{required && <span className="text-destructive ml-1">*</span>}</FormLabel>
          <FormControl>
            <div className="flex items-center gap-2">
              <Input
                type="color"
                {...field}
                disabled={disabled}
                className="h-10 w-20 p-1"
              />
              {showHexInput && (
                <Input
                  type="text"
                  value={field.value || "#000000"}
                  onChange={(e) => field.onChange(e.target.value)}
                  disabled={disabled}
                  className="flex-1"
                  placeholder="#000000"
                />
              )}
            </div>
          </FormControl>
          {description && <p className="text-sm text-muted-foreground">{description}</p>}
          <FormMessage />
        </FormItem>
      )}
    />
  );
}

// File Input
interface FileInputProps<TFieldValues extends FieldValues> {
  name: FieldPath<TFieldValues>;
  label: string;
  description?: string;
  required?: boolean;
  disabled?: boolean;
  accept?: string;
  multiple?: boolean;
}

export function FileInput<TFieldValues extends FieldValues>({
  name,
  label,
  description,
  required,
  disabled,
  accept,
  multiple,
}: FileInputProps<TFieldValues>) {
  const { control } = useFormContext<TFieldValues>();
  const [fileName, setFileName] = React.useState<string>("");

  return (
    <FormField
      control={control}
      name={name}
      render={({ field: { onChange, ...fieldProps } }) => (
        <FormItem>
          <FormLabel>{label}{required && <span className="text-destructive ml-1">*</span>}</FormLabel>
          <FormControl>
            <div className="flex flex-col gap-2">
              <Input
                type="file"
                accept={accept}
                multiple={multiple}
                disabled={disabled}
                onChange={(e) => {
                  const files = e.target.files;
                  if (files?.length) {
                    onChange(multiple ? Array.from(files) : files[0]);
                    setFileName(
                      multiple
                        ? `${files.length} files selected`
                        : files[0].name
                    );
                  }
                }}
                {...fieldProps}
                className="hidden"
                id={`file-input-${name}`}
              />
              <div className="flex gap-2">
                <Label
                  htmlFor={`file-input-${name}`}
                  className="cursor-pointer inline-flex h-9 items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground ring-offset-background transition-colors hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50"
                >
                  Browse...
                </Label>
                <div className="border rounded-md px-3 py-2 w-full text-sm">
                  {fileName || "No file selected"}
                </div>
              </div>
            </div>
          </FormControl>
          {description && <p className="text-sm text-muted-foreground">{description}</p>}
          <FormMessage />
        </FormItem>
      )}
    />
  );
}

// DateRange Input
interface DateRangeInputProps<TFieldValues extends FieldValues> {
  name: FieldPath<TFieldValues>;
  label?: string;
  placeholder?: string;
  description?: string;
  required?: boolean;
  disabled?: boolean;
}

export function DateRangeInput<TFieldValues extends FieldValues>({
  name,
  label,
  placeholder,
  description,
  required,
  disabled,
}: DateRangeInputProps<TFieldValues>) {
  const { control } = useFormContext<TFieldValues>();

  return (
    <FormField
      control={control}
      name={name}
      render={({ field }) => (
        <FormItem>
          {label && (
            <FormLabel>
              {label}
              {required && <span className="text-destructive ml-1">*</span>}
            </FormLabel>
          )}
          <FormControl>
            <DateRangePicker
              name={name as string}
              value={field.value}
              onChange={field.onChange}
              placeholder={placeholder}
              disabled={disabled}
            />
          </FormControl>
          {description && <p className="text-sm text-muted-foreground">{description}</p>}
          <FormMessage />
        </FormItem>
      )}
    />
  );
} 