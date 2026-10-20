# Imágenes de producto

Una imagen por producto, nombrada con su **código interno**:

```
BNS01-00001.jpg
BNS03-12491.png
```

La usan el catálogo de ofertas en PDF (`app/helpers/catalogo_pdf.php`) y, más
adelante, el catálogo en línea del subdominio: una sola imagen sirve para los dos.

## Estándar

| | |
|---|---|
| Proporción | **1:1 (cuadrada)** |
| Tamaño | **800 × 800 px** (mínimo 600 × 600) |
| Formato | JPG, o PNG **no entrelazado** (FPDF no lee WebP) |
| Fondo | blanco o transparente |
| Encuadre | producto centrado, ~5% de margen libre por lado |
| Peso | ≤ 200 KB |

Una imagen con otra proporción no se deforma: se ajusta completa dentro del
cuadro y deja franjas en blanco. Si un producto no tiene imagen, el PDF dibuja un
recuadro gris del mismo tamaño.

Las imágenes no se suben a Git (ver `.gitignore`): se copian directo al servidor.
