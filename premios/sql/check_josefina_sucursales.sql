-- Diagnóstico ad-hoc (no forma parte del setup): ¿a qué SUPERVISORA está asignada cada
-- sucursal de interés (Unicenter, GTNUNI, Tom, Tortugas) en cada mes reciente? Corridas
-- contra POWER_BI_CONTROL.dbo.BI_T_ESTADISTICAS_VENTAS_PROPIOS (conexión 'power', ver
-- PremiosDB.php). Ajustá el filtro de FECHA si querés otro rango.

SELECT
    FECHA,
    NRO_SUCURS,
    SUCURSAL,
    SUPERVISORA,
    IMP_FACT,
    IMP_OBJ
FROM BI_T_ESTADISTICAS_VENTAS_PROPIOS
WHERE SUCURSAL IN ('UNICENTER', 'GTNUNI', 'TOM', 'TORTUGAS')
   OR SUCURSAL LIKE '%UNICENTER%'
   OR SUCURSAL LIKE '%TORTUGA%'
ORDER BY SUCURSAL, FECHA DESC;

-- Complementario: para ver TODAS las sucursales que la base asocia HOY a Josefina
-- Pastorino en el último mes con datos, y comparar contra la lista que tenía yo
-- (Unicenter, GTNUNI, Tom, Malvinas, Dot, Solar):

SELECT NRO_SUCURS, SUCURSAL, FECHA, IMP_FACT, IMP_OBJ
FROM BI_T_ESTADISTICAS_VENTAS_PROPIOS
WHERE UPPER(SUPERVISORA) = UPPER('Josefina Pastorino')
  AND FECHA = (SELECT MAX(FECHA) FROM BI_T_ESTADISTICAS_VENTAS_PROPIOS)
ORDER BY SUCURSAL;
