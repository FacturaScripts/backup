$(document).ready(function () {
    // Esperar un poco para asegurar que Select2 esté inicializado
    setTimeout(function() {
        const frequencyContainer = $('#backup_frequency');
        const weeklyDayContainer = $('#backup_weekly_day');
        const monthlyDayContainer = $('#backup_monthly_day');

        // Si no encontramos los elementos, salimos (estamos en otra página de Settings)
        if (frequencyContainer.length === 0 || weeklyDayContainer.length === 0 || monthlyDayContainer.length === 0) {
            return;
        }

        // Obtener el select de frecuencia que está dentro del contenedor
        const frequencySelect = frequencyContainer.find('select[name="frequency"]');
        if (frequencySelect.length === 0) {
            return;
        }

        const toggleVisibility = function() {
            const value = frequencySelect.val();

            if (value === '1 week') {
                weeklyDayContainer.show();
                monthlyDayContainer.hide();
            } else if (value === '1 month') {
                weeklyDayContainer.hide();
                monthlyDayContainer.show();
            } else {
                // Para frecuencia diaria, ocultamos ambos selectores
                weeklyDayContainer.hide();
                monthlyDayContainer.hide();
            }
        };

        // Escuchar cambios con jQuery (funciona mejor con Select2)
        frequencySelect.on('change', toggleVisibility);

        // Ejecutar al cargar
        toggleVisibility();
    }, 100);
});
