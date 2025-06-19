import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ScrollArea } from '@/components/ui/scroll-area';
import { cn } from '@/lib/utils';
import {
    AlertCircle,
    AlertTriangle,
    AlignCenter,
    AlignJustify,
    AlignLeft,
    AlignRight,
    Anchor,
    Angry,
    Archive,
    ArrowDown,
    ArrowLeft,
    ArrowRight,
    ArrowUp,
    AtSign,
    Battery,
    Bed,
    Beer,
    Bell,
    Bike,
    Bitcoin,
    Bold,
    Book,
    Bookmark,
    Briefcase,
    Building,
    Bus,
    Calendar,
    Camera,
    Car,
    Check,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    ChevronUp,
    Church,
    Clipboard,
    Clock,
    Cloud,
    CloudRain,
    CloudSnow,
    Code,
    Coffee,
    Coins,
    Columns,
    Copy,
    CreditCard,
    Crop,
    DollarSign,
    Download,
    Droplet,
    Dumbbell,
    Euro,
    ExternalLink,
    Eye,
    EyeOff,
    Factory,
    FastForward,
    File,
    FileText,
    Film,
    Filter,
    Flag,
    Flame,
    Folder,
    Frown,
    Gamepad2,
    Gift,
    Glasses,
    Globe,
    GraduationCap,
    Grid,
    Hash,
    Headphones,
    Heart,
    HelpCircle,
    Home,
    Hospital,
    Image,
    Inbox,
    Info,
    Italic,
    Key,
    Laptop,
    Laugh,
    Layout,
    Leaf,
    LifeBuoy,
    Link,
    Link2,
    List,
    ListChecks,
    ListOrdered,
    Lock,
    LucideIcon,
    Mail,
    Map,
    Maximize,
    Maximize2,
    Megaphone,
    Meh,
    Menu,
    MessageSquare,
    Mic,
    Minimize,
    Minimize2,
    Minus,
    Moon,
    MoreHorizontal,
    MoreVertical,
    Mountain,
    Move,
    MoveHorizontal,
    MoveVertical,
    Music,
    Navigation,
    Palette,
    Pause,
    Percent,
    Phone,
    PiggyBank,
    Pill,
    Pizza,
    Plane,
    Play,
    Plus,
    Printer,
    Quote,
    Receipt,
    RefreshCcw,
    RefreshCw,
    Repeat,
    Rewind,
    RotateCcw,
    RotateCw,
    Rows,
    School,
    Scissors,
    Search,
    Send,
    Share2,
    Shield,
    Ship,
    Shirt,
    ShoppingBag,
    Shuffle,
    Sidebar,
    SkipBack,
    SkipForward,
    Smartphone,
    Smile,
    Snowflake,
    Speaker,
    Star,
    Stethoscope,
    Store,
    Strikethrough,
    Sun,
    ThumbsDown,
    ThumbsUp,
    Train,
    Trash2,
    Trees,
    Tv,
    Type,
    Umbrella,
    Underline,
    Unlink,
    Upload,
    Utensils,
    Video,
    Volume,
    Volume1,
    Volume2,
    VolumeX,
    Wallet,
    Watch,
    Wifi,
    Wind,
    Wine,
    X,
    ZoomIn,
    ZoomOut,
} from 'lucide-react';
import { useState } from 'react';

interface IconPickerProps {
    value: string | null;
    onChange: (value: string | null) => void;
    className?: string;
}

const icons: Record<string, LucideIcon> = {
    ShoppingBag,
    Home,
    Car,
    Utensils,
    Plane,
    Train,
    Bus,
    Bike,
    Heart,
    Gift,
    Book,
    GraduationCap,
    Briefcase,
    Stethoscope,
    Dumbbell,
    Gamepad2,
    Music,
    Film,
    Camera,
    Palette,
    Pizza,
    Coffee,
    Beer,
    Wine,
    Pill,
    Bed,
    Shirt,
    Watch,
    Glasses,
    Smartphone,
    Laptop,
    Tv,
    Headphones,
    Speaker,
    Printer,
    Wifi,
    Battery,
    CreditCard,
    Wallet,
    PiggyBank,
    Coins,
    Receipt,
    FileText,
    Calendar,
    Clock,
    Map,
    Navigation,
    Flag,
    Globe,
    Building,
    Factory,
    Store,
    School,
    Hospital,
    Church,
    Trees,
    Leaf,
    Sun,
    Moon,
    Cloud,
    CloudRain,
    CloudSnow,
    Wind,
    Umbrella,
    Snowflake,
    Flame,
    Droplet,
    Mountain,
    Ship,
    Anchor,
    LifeBuoy,
    Shield,
    Lock,
    Key,
    Bell,
    Megaphone,
    MessageSquare,
    Phone,
    Mail,
    Send,
    Inbox,
    Archive,
    Trash2,
    Folder,
    File,
    Image,
    Video,
    Mic,
    Volume2,
    VolumeX,
    Volume1,
    Volume,
    Play,
    Pause,
    SkipBack,
    SkipForward,
    Rewind,
    FastForward,
    Shuffle,
    Repeat,
    List,
    Grid,
    Layout,
    Columns,
    Rows,
    Sidebar,
    Menu,
    MoreHorizontal,
    MoreVertical,
    Plus,
    Minus,
    X,
    Check,
    AlertCircle,
    AlertTriangle,
    Info,
    HelpCircle,
    ExternalLink,
    Download,
    Upload,
    Share2,
    Copy,
    Scissors,
    Clipboard,
    Bookmark,
    Star,
    ThumbsUp,
    ThumbsDown,
    Smile,
    Frown,
    Meh,
    Laugh,
    Angry,
    Eye,
    EyeOff,
    Filter,
    Search,
    ZoomIn,
    ZoomOut,
    RotateCcw,
    RotateCw,
    RefreshCw,
    RefreshCcw,
    ArrowUp,
    ArrowDown,
    ArrowLeft,
    ArrowRight,
    ChevronUp,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Move,
    MoveHorizontal,
    MoveVertical,
    Maximize,
    Minimize,
    Maximize2,
    Minimize2,
    Crop,
    Type,
    Bold,
    Italic,
    Underline,
    Strikethrough,
    AlignLeft,
    AlignCenter,
    AlignRight,
    AlignJustify,
    ListOrdered,
    ListChecks,
    Quote,
    Code,
    Link,
    Link2,
    Unlink,
    Hash,
    AtSign,
    Percent,
    DollarSign,
    Euro,
    Bitcoin,
};

export { icons };
export const iconNames = Object.keys(icons);

export function IconPicker({ value, onChange, className }: IconPickerProps) {
    const [isOpen, setIsOpen] = useState(false);
    const [searchQuery, setSearchQuery] = useState('');

    const filteredIcons = iconNames.filter((iconName) => iconName.toLowerCase().includes(searchQuery.toLowerCase()));

    const SelectedIcon = value ? icons[value] : null;

    return (
        <div className={className}>
            <Button
                variant="outline"
                className="w-full justify-start gap-2"
                onClick={(e) => {
                    e.preventDefault();
                    setIsOpen(true);
                }}
            >
                {SelectedIcon ? (
                    <>
                        <SelectedIcon className="h-4 w-4" />
                        <span>{value}</span>
                    </>
                ) : (
                    <span>Select an icon</span>
                )}
            </Button>

            <Dialog open={isOpen} onOpenChange={setIsOpen}>
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Select an icon</DialogTitle>
                        <DialogDescription>Choose an icon for your category</DialogDescription>
                    </DialogHeader>

                    <div className="relative">
                        <input
                            type="text"
                            placeholder="Search icons..."
                            className="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                }
                            }}
                        />
                    </div>

                    <ScrollArea className="h-[400px]">
                        <div className="grid grid-cols-8 gap-2 p-2">
                            {filteredIcons.map((iconName) => {
                                const Icon = icons[iconName];
                                return (
                                    <Button
                                        key={iconName}
                                        variant="ghost"
                                        className={cn('h-12 w-12 p-0', value == iconName && 'bg-accent')}
                                        onClick={(e) => {
                                            e.preventDefault();
                                            onChange(iconName);
                                            setIsOpen(false);
                                        }}
                                    >
                                        <Icon className="h-6 w-6" />
                                    </Button>
                                );
                            })}
                        </div>
                    </ScrollArea>
                </DialogContent>
            </Dialog>
        </div>
    );
}
