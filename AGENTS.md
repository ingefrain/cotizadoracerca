# Cotizador Acerca — Guía operativa

Cotizador Acerca es una aplicación interna para elaborar, editar, previsualizar y versionar documentos PDF corporativos a partir de Markdown. Conserva su nombre provisional y deriva de Proyecto Base en el commit 293cf58743da881f06c2ec2b83a6e35ee922ee8d. El repositorio original no se modifica. Fase 0.1 redefine el alcance y publica la aplicación derivada; el dominio se implementará únicamente cuando se autorice Fase 1.

## Fuentes de verdad

La aplicación Laravel, dependencias, recursos operativos y pruebas automatizadas pertenecen a site. Todos los auxiliares (informes, scripts de preparación, respaldos, referencias originales y documentación) pertenecen a ../xtras. Documentación en ../xtras/docs y originales de aceptación en ../xtras/referencias. No crear site/docs ni guardar informes o scripts auxiliares en la raíz de site. Este AGENTS.md es la guía operativa del repositorio.
Antes de implementar, identifica la tarea y consulta la guía pertinente. Si no sabes cuál corresponde, empieza por ../xtras/docs/INDICE_DOCUMENTACION.md.

- ROADMAP.md para fases planificadas.
- COMPONENTES_REUTILIZABLES.md antes de crear vistas o componentes.
- POLITICA_DATOS_PRUEBA.md para seeders, factories, fixtures y limpieza.
- DERIVAR_NUEVO_PROYECTO.md para crear un proyecto independiente.
- PREPARACION_PROYECTOS_DERIVADOS.md para límites y dependencias operativas.
- FASE_0_DERIVACION.md para procedencia, configuración y comprobaciones de esta derivación.
- CASO_ACEPTACION_BARBERIA_REYES.md para el alcance de referencia, aún sin implementar.
- ADR_001_DOCUMENTOS_MARKDOWN.md para la decisión vigente que sustituye el modelo comercial.
- ARQUITECTURA_DOCUMENTOS_MARKDOWN.md para el modelo editorial conceptual y snapshots.
- FASE_0_1_REDEFINICION_GIT.md para la redefinición y publicación inicial.
- ENTORNO_LOCAL.md y FASE_0_2_ENTORNO_OPERATIVO.md para el entorno local habilitado, herramientas y comprobaciones.
- IDENTIDAD_CROMATICA_ACERCA.md para la paleta, contraste y favicon derivado del logotipo.

Todos esos documentos están en ../xtras/docs. Las migraciones definen el esquema.

## Convenciones visuales

Revisa las vistas existentes. Usa superficie administrativa centrada o amplia y grid responsive. Cada campo agrupa label, control, ayuda y error. Evita tarjetas limitadas por max-width pegadas a la izquierda.
Reutiliza Dropzone, Markdown, Toggle para estados reversibles, Notify para feedback temporal, Alert para información persistente y Modal/Confirm Dialog para confirmaciones. Evita window.alert() y window.confirm(). Revisa los componentes propios antes de introducir otra dependencia.
La paleta se centraliza en public/assets/base-admin/brand.css; conservar los colores semánticos y el contraste. El favicon usa una miniatura PNG de 64 × 64 del logotipo privado, sin recortes, y fallback CA. Su snapshot privado permite pre-renderizar mantenimiento sin consultas a la base. Consultar la guía de identidad antes de cambiar este flujo.

## Datos y seguridad

Seeders solo estructurales e idempotentes. Factories para tests y fixtures pequeñas por escenario. No crear precarga DEMO global ni depender de registros de desarrollo.
No usar migrate:fresh en desarrollo o producción. Revisa migraciones con migrate --pretend. Conserva los datos manuales del usuario.
No modificar .env sin autorización explícita ni mostrar secretos. Conserva autorización server-side y actor real durante impersonación. No force push.
La suite rápida usa SQLite :memory:. La integración MariaDB exige OUTBOX_MARIADB_TEST=1, 127.0.0.1:3307, cotizadoracerca_test y cotizadoracerca_test_runner; TestDatabaseSafety valida antes de abrir PDO. Nunca reutilizar bases o credenciales de Proyecto Base, desarrollo o producción para pruebas.
Para conexiones locales MySQL/MariaDB usar C:\MariaDB\11.4.13\bin\mariadb.exe con --defaults-file=C:\Users\ing_e\.codex\mysql.ini como primer argumento, seleccionar explícitamente la base y no mostrar ni copiar credenciales.

El entorno de desarrollo ya tiene base, migraciones heredadas y administrador propios; no repetir el aprovisionamiento. Antes de ejecutar npm en PowerShell desde site, cargar `. ..\xtras\herramientas\Activar-Entorno.ps1`: selecciona Node 24.22.2 LTS y mantiene cachés/temporales auxiliares en xtras, sin cambiar instalaciones globales. Los lanzadores Iniciar-Local.ps1 y Detener-Local.ps1 están en esa misma carpeta. MAIL_MAILER=array evita envíos reales; no acredita entrega externa. La suite MariaDB optativa sigue sin provisionar ni ejecutar: contiene migrate:fresh y no queda autorizada por la habilitación de desarrollo.

## Documentación y derivación

Al introducir una convención transversal, decide si corresponde a esta guía, al índice o a una guía temática. Mantén este archivo compacto.
Conservar las capacidades transversales heredadas y la política sin DEMO global. No usar datos reales de Barbería Reyes como seeds. El dominio conceptual es FormattedDocument editable y DocumentVersion congelada; Document existente sigue siendo el archivo de almacenamiento. Distinguir autor, persona presentada en cabecera y actor generador. Los PDFs históricos conservan snapshots y archivos privados, independientes de datos vigentes. Las notas internas opcionales quedan fuera del PDF y envío por defecto; email es posterior.
No crear Client, Quote, QuoteOption ni QuoteVersion. No administrar productos, CRM o alternativas comerciales estructuradas; no calcular cantidades por precios unitarios o impuestos, interpretar importes Markdown ni implementar estados de ventas. Los importes son texto del autor. Las capacidades heredadas instaladas no amplían este alcance. No instalar PDF ni crear código de dominio en las fases 0.1 y 0.2.
El Git local está en site y no incluye ../xtras: la documentación requiere respaldo/versionado independiente. El único origin autorizado es https://github.com/ingefrain/cotizadoracerca.git; publicar solo contenido revisado y sin secretos. La autorización de commit inicial y push de main correspondió a Fase 0.1; Fase 0.2 deja los cambios preparados y prohíbe push automático. Ante conflicto de copia o sobrescritura no prevista, detener esa operación e informar; preservar los archivos originales. Conservar FASE_0_DERIVACION.md y FASE_0_1_REDEFINICION_GIT.md como informes históricos, sin reescribir su alcance original.
