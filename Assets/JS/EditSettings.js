document.addEventListener('DOMContentLoaded', function () {
    const frequencySelect = document.getElementById('backup_frequency');
    const weeklyDaySelect = document.getElementById('weekly_backup_day');
    const monthlyDayInput = document.getElementById('monthly_backup_day');

    // Find the parent container of the inputs to hide the label as well.
    // You may need to inspect the page source in your browser to find the exact parent element and its class.
    //const weeklyContainer = weeklyDaySelect ? weeklyDaySelect.closest('div.col-sm-3') : null;
    //const monthlyContainer = monthlyDayInput ? monthlyDayInput.closest('div.col-sm-3') : null;

    const toggleVisibility = () => {
        if (frequencySelect.value === '1 week') {
            weeklyDaySelect.style.display = '';
            monthlyDayInput.style.display = 'none';
        } else if (frequencySelect.value === '1 month') {
            weeklyDaySelect.style.display = 'none';
            monthlyDayInput.style.display = '';
        } else {
            weeklyDaySelect.style.display = 'none';
            monthlyDayInput.style.display = 'none';
        }
    };

    frequencySelect.addEventListener('change', toggleVisibility);
    toggleVisibility();
});
