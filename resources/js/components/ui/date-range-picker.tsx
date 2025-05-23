import * as React from "react";
import { useState, useEffect } from "react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Calendar as CalendarIcon, ChevronLeft, ChevronRight } from "lucide-react";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { format, addMonths, subMonths } from "date-fns";

export interface DateRangePickerProps {
  name: string;
  label?: string;
  placeholder?: string;
  disabled?: boolean;
  value?: { from: string; to: string } | undefined;
  onChange?: (value: { from: string; to: string }) => void;
  className?: string;
}

export function DateRangePicker({
  name,
  label,
  placeholder = "Select date range",
  disabled,
  value,
  onChange,
  className,
  ...props
}: DateRangePickerProps & Omit<React.HTMLAttributes<HTMLDivElement>, 'onChange' | 'value'>) {
  const [isOpen, setIsOpen] = useState(false);
  const [hoveredDate, setHoveredDate] = useState<Date | null>(null);
  const [internalValue, setInternalValue] = useState<{ from: string; to: string }>({ from: "", to: "" });
  const [currentMonth, setCurrentMonth] = useState(new Date());

  // Sync internal state with provided value
  useEffect(() => {
    if (value) {
      setInternalValue(value);
    }
  }, [value]);

  const dateFrom = internalValue.from ? new Date(internalValue.from) : null;
  const dateTo = internalValue.to ? new Date(internalValue.to) : null;

  // Reset selection when opening a new selection
  const handleOpen = (open: boolean) => {
    if (open && !isOpen) {
      setIsOpen(true);
      // Reset the current month view to the month of the start date if one exists
      if (dateFrom) {
        setCurrentMonth(new Date(dateFrom));
      } else {
        setCurrentMonth(new Date());
      }
    } else if (!open && isOpen) {
      setIsOpen(false);
    }
  };

  const formattedRange = React.useMemo(() => {
    if (dateFrom && dateTo) {
      return `${format(dateFrom, "PPP")} - ${format(dateTo, "PPP")}`;
    }
    if (dateFrom) {
      return `${format(dateFrom, "PPP")} - Select end date`;
    }
    return placeholder;
  }, [dateFrom, dateTo, placeholder]);

  const handleDateClick = (date: Date) => {
    let newValue: { from: string; to: string };

    if (!dateFrom || (dateFrom && dateTo)) {
      // Start a new selection
      newValue = { from: format(date, "yyyy-MM-dd"), to: "" };
    } else {
      // Complete the selection
      if (date < dateFrom) {
        // If selecting a date before the start date, swap them
        newValue = {
          from: format(date, "yyyy-MM-dd"),
          to: format(dateFrom, "yyyy-MM-dd")
        };
      } else {
        newValue = {
          from: format(dateFrom, "yyyy-MM-dd"),
          to: format(date, "yyyy-MM-dd")
        };
      }
      // Close the popover after selecting the range
      setIsOpen(false);
    }

    // Update internal state
    setInternalValue(newValue);

    // Call external onChange if provided
    if (onChange) {
      onChange(newValue);
    }
  };

  const handleMouseEnter = (date: Date) => {
    setHoveredDate(date);
  };

  const handleMouseLeave = () => {
    setHoveredDate(null);
  };

  const isInRange = (date: Date) => {
    if (dateFrom && !dateTo && hoveredDate) {
      return (
        (date >= dateFrom && date <= hoveredDate) ||
        (date >= hoveredDate && date <= dateFrom)
      );
    }
    if (dateFrom && dateTo) {
      return date >= dateFrom && date <= dateTo;
    }
    return false;
  };

  const isFirstDay = (date: Date) => {
    if (!dateFrom) return false;
    return date.getDate() === dateFrom.getDate() &&
           date.getMonth() === dateFrom.getMonth() &&
           date.getFullYear() === dateFrom.getFullYear();
  };

  const isLastDay = (date: Date) => {
    if (!dateTo) return false;
    return date.getDate() === dateTo.getDate() &&
           date.getMonth() === dateTo.getMonth() &&
           date.getFullYear() === dateTo.getFullYear();
  };

  const isHoveredLastDay = (date: Date) => {
    if (!hoveredDate || !dateFrom || dateTo) return false;
    return date.getDate() === hoveredDate.getDate() &&
           date.getMonth() === hoveredDate.getMonth() &&
           date.getFullYear() === hoveredDate.getFullYear() &&
           date > dateFrom;
  };

  const isSelectionStart = (date: Date) => {
    return dateFrom && date.getTime() === dateFrom.getTime();
  };

  const isSelectionEnd = (date: Date) => {
    return dateTo && date.getTime() === dateTo.getTime();
  };

  const clearDates = () => {
    const newValue = { from: "", to: "" };
    setInternalValue(newValue);
    setIsOpen(false); // Close popover after clearing
    
    // Call external onChange if provided
    if (onChange) {
      onChange(newValue);
    }
  };

  const goToPreviousMonth = () => {
    setCurrentMonth(prev => subMonths(prev, 1));
  };

  const goToNextMonth = () => {
    setCurrentMonth(prev => addMonths(prev, 1));
  };

  // Generate calendar
  const generateCalendar = () => {
    const currentMonthValue = currentMonth.getMonth();
    const currentYearValue = currentMonth.getFullYear();

    // Generate dates for current month
    const daysInMonth = new Date(currentYearValue, currentMonthValue + 1, 0).getDate();
    const firstDayOfMonth = new Date(currentYearValue, currentMonthValue, 1).getDay();

    // Adjust for Sunday as the first day of the week (0)
    const adjustedFirstDay = firstDayOfMonth === 0 ? 6 : firstDayOfMonth - 1;

    const days = [];

    // Generate day labels
    const dayLabels = ["Mo", "Tu", "We", "Th", "Fr", "Sa", "Su"];

    // Add day labels
    days.push(
      <div key="header" className="grid grid-cols-7 gap-1 mb-2">
        {dayLabels.map((day) => (
          <div key={day} className="text-center text-xs text-muted-foreground">
            {day}
          </div>
        ))}
      </div>
    );

    // Add empty cells for days before the first day of the month
    const cells = [];
    for (let i = 0; i < adjustedFirstDay; i++) {
      cells.push(<div key={`empty-${i}`} className="h-8 w-8" />);
    }

    // Add days of the month
    for (let day = 1; day <= daysInMonth; day++) {
      const date = new Date(currentYearValue, currentMonthValue, day);
      const isToday =
        date.getDate() === new Date().getDate() &&
        date.getMonth() === new Date().getMonth() &&
        date.getFullYear() === new Date().getFullYear();

      const inRange = isInRange(date);
      const isStart = isSelectionStart(date);
      const isEnd = isSelectionEnd(date);
      const isFirstInRange = isFirstDay(date);
      const isLastInRange = isLastDay(date);
      const isHoveredEnd = isHoveredLastDay(date);

      cells.push(
        <div
          key={`day-${day}`}
          className={cn(
            "h-8 w-8 flex items-center justify-center text-sm transition-colors cursor-pointer relative rounded-lg focus:outline-none hover:bg-primary/10 hover:text-foreground" ,
            // Today indicator
            isToday && "border border-primary",

            // Range styling with rounded corners for first and last day
            inRange && "bg-primary/15",
            isFirstInRange && "bg-foreground text-background",
            (isLastInRange || isHoveredEnd) && " bg-foreground text-background",

            // Special highlight for start/end dates
            isStart && "bg-foreground text-foreground font-bold z-10",
            isEnd && "bg-foreground text-foreground font-bold z-10",

              // Disabled state
            disabled && "opacity-50 cursor-not-allowed"
          )}
          onMouseEnter={() => !disabled && handleMouseEnter(date)}
          onMouseLeave={() => !disabled && handleMouseLeave()}
          onClick={() => !disabled && handleDateClick(date)}
        >
          {day}
        </div>
      );
    }

    // Combine empty cells and days
    days.push(
      <div key="days" className="grid grid-cols-7 gap-1">
        {cells}
      </div>
    );

    return days;
  };

  return (
    <div className={cn("relative", className)} {...props}>
      {label && (
        <label className="block text-sm font-medium mb-1">
          {label}
        </label>
      )}
      <Popover open={isOpen} onOpenChange={handleOpen}>
        <PopoverTrigger asChild>
          <Button
            variant="outline"
            className={cn(
              "w-full justify-between text-left font-normal",
              !dateFrom && !dateTo && "text-muted-foreground"
            )}
            disabled={disabled}
          >
            <span>{formattedRange}</span>
            <CalendarIcon className="h-4 w-4 opacity-50" />
          </Button>
        </PopoverTrigger>
        <PopoverContent className="w-auto p-0" align="start">
          <div className="p-3">
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <Button
                  variant="ghost"
                  size="icon"
                  onClick={goToPreviousMonth}
                  className="h-7 w-7"
                >
                  <ChevronLeft className="h-4 w-4" />
                  <span className="sr-only">Previous month</span>
                </Button>
                <h4 className="font-medium">
                  {format(currentMonth, "MMMM yyyy")}
                </h4>
                <div className="flex items-center gap-1">
                  {(dateFrom || dateTo) && (
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={clearDates}
                      className="h-7 px-2 text-xs"
                    >
                      Clear
                    </Button>
                  )}
                  <Button
                    variant="ghost"
                    size="icon"
                    onClick={goToNextMonth}
                    className="h-7 w-7"
                  >
                    <ChevronRight className="h-4 w-4" />
                    <span className="sr-only">Next month</span>
                  </Button>
                </div>
              </div>
              <div>{generateCalendar()}</div>
              <div className="pt-2 text-xs text-muted-foreground">
                {dateFrom ? (
                  dateTo ? (
                    <span>
                      Selected: {format(dateFrom, "PPP")} to {format(dateTo, "PPP")}
                    </span>
                  ) : (
                    <span>Select end date</span>
                  )
                ) : (
                  <span>Select start date</span>
                )}
              </div>
            </div>
          </div>
        </PopoverContent>
      </Popover>
    </div>
  );
}
