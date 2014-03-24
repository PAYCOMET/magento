# Módulo de pago de PayTpv para Magento


Ofrece la posibilidad de cobrar a tus clientes por tarjeta de crédito en las tiendas Magento.


## Instalación
 
### Método manual:
 
1. Desactivar la cache: Sistema-> Gestión de cache.
2. Subir los ficheros por FTP
3. Acceder a la configuración del módulo: sistema -> Configuración -> métodos de pago -> PayTPV
4. Introducir los datos del producto asociado en nuestra cuenta de PayTPV
 
### Vía modman
- Instalar [modman](https://github.com/colinmollenhour/modman)
- Usar el siguiente comando de línea desde el directorio de instalación de Magento: `modman clone https://github.com/PayTpv/Mage_PayTpv.git`


## Configuración

Accederemos a la configuración del módulo a través de Administración -> Sistema -> Configuración -> Métodos de Pago -> "Tarjeta de crédito paytpv". Ahí tendremos que indicar el Código de cliente, Nombre de usuario (si aplica), Número de terminal y Contraseña de usuario de nuestro producto en PayTPV.com.

En función del producto que tengamos contratado en PayTPV podremos configurar un tipo de operativa u otro.


### Operativa TPVWEB

El cliente tendrá que introducir el número de tarjeta de crédito en la página de PayTPV.com.
Esta operativa podremos integrarla de 2 formas diferentes

1.- Tipo de integración OFFSITE:
Una vez el cliente confirme el pedido este será redirigido a la página de payptv.com. Una página segura, con certificado SSL donde podrá introducir los datos de la tarjeta con todas las garantías.
    
2.- Tipo de integración IFRAME:
Una vez el cliente confirme el pedido se cargará dentro de la tienda incrustado en un iframe el formulario que solicita los datos de la tarjeta. Este formulario se puede personalizar en la cuenta de payptv.com y envía los datos siempre a paytpv.com a través de una conexión encriptada, con todas las garatías de seguridad.


### Operativa BANKSTORE

Esta operativa nos permite integrar completamente la experiencia de compra dentro de nuestra tienda Magento. Necesitaremos tener contratado un terminal de tipo BANKSTORE para poder usarla.
    
El proceso de compra en este caso es algo diferente:

Si es la primera compra del cliente en nuestra tienda, al seleccionar "Tarjeta de crédito" como método de pago, se le solicitarán los datos de la tarjeta, y sólo cunado confirme el pedido se le realizará el cargo.
  
Si el cliente ya ha pagado algún pedido anterior con este método de pago el cliente no tendrá que volver a introducir los datos de la tarjeta, simplemente con seleccionar el método de pago "Tarjeta de crédito" cuando confirme el pedido se le hará el cargo en la tarjeta. Aún así en la elección de método de pago se le da la opción al cliente de introducir los datos de otra tarjeta si quiere realizar el pago con una tarjeta diferente a la que haya utilizado con anterioridad en esta tienda.
  
Con esta operativa conseguimos reducir los pasos para completar una compra al mínimo.
  