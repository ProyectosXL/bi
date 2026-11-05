/* ============================================================================
   KPIs INNOVACION - VALIDACION DE COMPROBANTES DE TANGO PARA "BAJA DE PROVEEDOR"
   ----------------------------------------------------------------------------
   Base   : central (Tango: CPA04 comprobantes de compra, CPA01 proveedores)
   Tipo   : SOLO LECTURA. No crea, modifica ni borra nada.
   ----------------------------------------------------------------------------
   PARA QUE SIRVE

   Antes de programar el calculo del ahorro por baja de proveedor hay que
   definir, con datos reales:
     - que valores de T_COMP son factura, nota de credito, nota de debito y
       orden de pago;
     - que valor de ESTADO marca un comprobante anulado;
     - con que columnas se arma el importe "sin IVA";
     - si los importes de CPA04 estan en la moneda del comprobante o ya en
       pesos, y como juega COTIZACION.

   Se corre completo y se pegan los resultados de cada bloque (1 a 5).
   ========================================================================== */

SET NOCOUNT ON;

DECLARE @DESDE DATE = '2026-01-01';

/* Proveedores de la prueba. Los JOIN contra @PROV llevan COLLATE DATABASE_DEFAULT:
   las columnas de Tango tienen otra collation (Latin1_General_BIN) que la
   variable de tabla, y sin eso SQL Server rechaza la comparacion. */
DECLARE @PROV TABLE (COD_PROVEE VARCHAR(20) PRIMARY KEY);
INSERT INTO @PROV VALUES ('OGMAGA'), ('OGQUIP');


/* ---- 1. Valores distintos para OGMAGA y OGQUIP desde @DESDE ---------------
   Uno por columna, con su cantidad: T_COMP, ESTADO, MON_CTE y COTIZACION. */

SELECT '1a T_COMP' AS bloque, C.T_COMP AS valor, COUNT(*) AS cantidad
FROM dbo.CPA04 C JOIN @PROV P ON P.COD_PROVEE = C.COD_PROVEE COLLATE DATABASE_DEFAULT
WHERE C.FECHA_EMIS >= @DESDE
GROUP BY C.T_COMP
ORDER BY C.T_COMP;

SELECT '1b ESTADO' AS bloque, C.ESTADO AS valor, COUNT(*) AS cantidad
FROM dbo.CPA04 C JOIN @PROV P ON P.COD_PROVEE = C.COD_PROVEE COLLATE DATABASE_DEFAULT
WHERE C.FECHA_EMIS >= @DESDE
GROUP BY C.ESTADO
ORDER BY C.ESTADO;

SELECT '1c MON_CTE' AS bloque, C.MON_CTE AS valor, COUNT(*) AS cantidad
FROM dbo.CPA04 C JOIN @PROV P ON P.COD_PROVEE = C.COD_PROVEE COLLATE DATABASE_DEFAULT
WHERE C.FECHA_EMIS >= @DESDE
GROUP BY C.MON_CTE
ORDER BY C.MON_CTE;

SELECT '1d COTIZACION' AS bloque, C.COTIZACION AS valor, COUNT(*) AS cantidad
FROM dbo.CPA04 C JOIN @PROV P ON P.COD_PROVEE = C.COD_PROVEE COLLATE DATABASE_DEFAULT
WHERE C.FECHA_EMIS >= @DESDE
GROUP BY C.COTIZACION
ORDER BY C.COTIZACION;


/* ---- 2. Por comprobante, OGMAGA y OGQUIP desde @DESDE ----------------------
   Las columnas pedidas, mas tres controles para ver que suma con que:
     CTRL_TO_MENOS_IVA   = IMPORTE_TO - (IMP_IVA1..5)
     CTRL_GRAVA_EXENT    = SUBT_GRAVA + SUBT_EXENT
     CTRL_NE_EX_IN       = IMPORTE_NE + IMPORTE_EX + IMPORTE_IN
   y IMPORTE_EX / IMPORTE_FL / IMPORTE_AN, que tambien pueden formar parte
   del total. */

SELECT
    '2 comprobantes' AS bloque,
    C.COD_PROVEE, PR.NOM_PROVEE, PR.COND_IVA,
    C.T_COMP, C.N_COMP, C.TIPO_COMPR, C.ESTADO,
    CONVERT(VARCHAR(10), C.FECHA_EMIS, 23) AS FECHA_EMIS,
    C.MON_CTE, C.COTIZACION,
    C.IMPORTE_TO,
    (ISNULL(C.IMP_IVA1,0) + ISNULL(C.IMP_IVA2,0) + ISNULL(C.IMP_IVA3,0)
     + ISNULL(C.IMP_IVA4,0) + ISNULL(C.IMP_IVA5,0))          AS IVA_TOTAL,
    C.IMPORTE_NE, C.SUBT_GRAVA, C.SUBT_EXENT, C.IMPORTE_IN, C.IMPORTE_BO,
    C.IMPORTE_EX, C.IMPORTE_FL, C.IMPORTE_AN,
    C.IMPORTE_TO - (ISNULL(C.IMP_IVA1,0) + ISNULL(C.IMP_IVA2,0) + ISNULL(C.IMP_IVA3,0)
                    + ISNULL(C.IMP_IVA4,0) + ISNULL(C.IMP_IVA5,0)) AS CTRL_TO_MENOS_IVA,
    ISNULL(C.SUBT_GRAVA,0) + ISNULL(C.SUBT_EXENT,0)           AS CTRL_GRAVA_EXENT,
    ISNULL(C.IMPORTE_NE,0) + ISNULL(C.IMPORTE_EX,0) + ISNULL(C.IMPORTE_IN,0) AS CTRL_NE_EX_IN
FROM dbo.CPA04 C
JOIN @PROV P ON P.COD_PROVEE = C.COD_PROVEE COLLATE DATABASE_DEFAULT
LEFT JOIN dbo.CPA01 PR ON PR.COD_PROVEE = C.COD_PROVEE
WHERE C.FECHA_EMIS >= @DESDE
ORDER BY C.COD_PROVEE, C.FECHA_EMIS, C.T_COMP, C.N_COMP;


/* ---- 3. Comprobantes en moneda extranjera (cualquier proveedor) ------------
   Los 15 mas recientes con MON_CTE = 0 desde @DESDE, para ver si los importes
   estan en la moneda del comprobante o ya en pesos. Si IMPORTE_TO es "chico"
   y IMPORTE_TO * COTIZACION da un monto razonable en pesos, estan en moneda
   extranjera. Se agrega la moneda habitual del proveedor (CPA01.MON_CTE_HA). */

SELECT TOP 15
    '3 moneda extranjera' AS bloque,
    C.COD_PROVEE, PR.NOM_PROVEE, PR.COND_IVA, PR.MON_CTE_HA,
    C.T_COMP, C.N_COMP, C.ESTADO,
    CONVERT(VARCHAR(10), C.FECHA_EMIS, 23) AS FECHA_EMIS,
    C.MON_CTE, C.COTIZACION,
    C.IMPORTE_TO, C.IMPORTE_NE, C.SUBT_GRAVA, C.SUBT_EXENT,
    C.IMPORTE_TO * C.COTIZACION AS TO_POR_COTIZACION
FROM dbo.CPA04 C
LEFT JOIN dbo.CPA01 PR ON PR.COD_PROVEE = C.COD_PROVEE
WHERE C.FECHA_EMIS >= @DESDE
  AND C.MON_CTE = 0
  AND C.T_COMP <> 'O/P'
ORDER BY C.FECHA_EMIS DESC;


/* ---- 4. Universo completo de T_COMP / TIPO_COMPR / ESTADO desde @DESDE -----
   Para todos los proveedores: asi no queda afuera un tipo de nota de credito
   o un estado de anulacion que OGMAGA y OGQUIP no tengan. */

SELECT
    '4 universo' AS bloque,
    C.T_COMP, C.TIPO_COMPR, C.ESTADO, C.MON_CTE,
    COUNT(*) AS cantidad,
    SUM(CASE WHEN C.IMPORTE_TO < 0 THEN 1 ELSE 0 END) AS con_importe_negativo
FROM dbo.CPA04 C
WHERE C.FECHA_EMIS >= @DESDE
GROUP BY C.T_COMP, C.TIPO_COMPR, C.ESTADO, C.MON_CTE
ORDER BY C.T_COMP, C.TIPO_COMPR, C.ESTADO, C.MON_CTE;


/* ---- 5. Condicion fiscal de los proveedores de la prueba ------------------ */

SELECT '5 proveedores' AS bloque, PR.COD_PROVEE, PR.NOM_PROVEE, PR.COND_IVA, PR.MON_CTE_HA,
       PR.FECHA_INHA, PR.HABILITADO
FROM dbo.CPA01 PR
JOIN @PROV P ON P.COD_PROVEE = PR.COD_PROVEE COLLATE DATABASE_DEFAULT;


/* ---- 6. Catalogo de tipos de comprobante de compras (CPA21) ----------------
   CRE_DEB dice el signo (C = credito, resta; D = debito, suma) y
   CLASE_OPERACION la clase (F factura, NC nota de credito, ND nota de debito).
   Las O/P no estan en CPA21: son de tesoreria. */

SELECT '6 tipos CPA21' AS bloque, T_COMP, DESC_COMP, CRE_DEB, CLASE_OPERACION
FROM dbo.CPA21
ORDER BY CLASE_OPERACION, T_COMP;


/* ---- 7. Facturas por ESTADO y condicion de compra desde @DESDE ------------
   Para saber si ESTADO = '***' es un anulado o una compra de contado
   (COND_COMPR = 1). */

SELECT '7 estado x condicion' AS bloque, C.ESTADO, C.COND_COMPR, COUNT(*) AS cantidad
FROM dbo.CPA04 C
WHERE C.FECHA_EMIS >= @DESDE AND C.T_COMP = 'FAC'
GROUP BY C.ESTADO, C.COND_COMPR
ORDER BY C.ESTADO, C.COND_COMPR;


/* ---- 8. Que parte de IMPORTE_TO no es neto + exento + IVA -----------------
   Para facturas y notas desde @DESDE. En moneda extranjera (MON_CTE = 0) los
   componentes se multiplican por COTIZACION, porque IMPORTE_TO ya esta en
   pesos y el resto no. DIF_SOBRE_NETO_PCT muestra si la diferencia es un
   porcentaje fijo del neto (percepciones). */

SELECT TOP 20
    '8 conciliacion' AS bloque,
    C.COD_PROVEE, C.T_COMP, C.N_COMP, C.MON_CTE, C.COTIZACION, C.IMPORTE_TO,
    C.IMPORTE_NE, C.IMPORTE_EX,
    (C.IMP_IVA1 + C.IMP_IVA2 + C.IMP_IVA3 + C.IMP_IVA4 + C.IMP_IVA5) AS IVA_TOTAL,
    C.IMPORTE_TO - (C.IMPORTE_NE + C.IMPORTE_EX + C.IMP_IVA1 + C.IMP_IVA2 + C.IMP_IVA3
                    + C.IMP_IVA4 + C.IMP_IVA5)
                   * CASE WHEN C.MON_CTE = 0 THEN C.COTIZACION ELSE 1 END AS DIF_PESOS,
    CAST(100.0 * (C.IMPORTE_TO - (C.IMPORTE_NE + C.IMPORTE_EX + C.IMP_IVA1 + C.IMP_IVA2
                  + C.IMP_IVA3 + C.IMP_IVA4 + C.IMP_IVA5)
                  * CASE WHEN C.MON_CTE = 0 THEN C.COTIZACION ELSE 1 END)
         / NULLIF(C.IMPORTE_NE * CASE WHEN C.MON_CTE = 0 THEN C.COTIZACION ELSE 1 END, 0)
         AS DECIMAL(9,2)) AS DIF_SOBRE_NETO_PCT
FROM dbo.CPA04 C
JOIN dbo.CPA21 T ON T.T_COMP = C.T_COMP
WHERE C.FECHA_EMIS >= @DESDE
  AND T.CLASE_OPERACION IN ('F', 'NC', 'ND')
  AND ABS(C.IMPORTE_TO - (C.IMPORTE_NE + C.IMPORTE_EX + C.IMP_IVA1 + C.IMP_IVA2 + C.IMP_IVA3
                          + C.IMP_IVA4 + C.IMP_IVA5)
                         * CASE WHEN C.MON_CTE = 0 THEN C.COTIZACION ELSE 1 END) >= 1
ORDER BY C.FECHA_EMIS DESC;
