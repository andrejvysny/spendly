export const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleString('sk-SK', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

export const formatDateShort = (dateString: string) => {
    return new Date(dateString).toLocaleString('sk-SK', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
};
