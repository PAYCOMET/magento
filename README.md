# Módulo de pago de PayTpv para Magento


Ofrece la posibilidad de cobrar a tus clientes por tarjeta de crédito en las tiendas Magento.


## Instalación
 
### Método manual:
 
1. Desactivar la cache: Sistema-> Gestión de cache.
2. Subir los ficheros por FTP
3. Acceder a la configuración del módulo: sistema -> Configuración -> métodos de pago -> PayTPV
4. Introducir los datos del producto asociado en nuestra cuenta de PayTPV

### Vía Magento Connect Manager
#### Versión estable
- Descargamos el fichero Mage_PayTpv-xxx.tgz desde [https://github.com/PayTpv/Mage_PayTpv/releases/latest] 
- Desde la administración de magento accedemos a Sistema -> Magento Connect -> Magento Connect Manager -> Direct package file upload Seleccionamos el fichero recién descargado y lo subimos.

#### Versión en desarrollo
- Para crear el paquete que instalaremos a través de Magento Connect Manager, primero clonamos el repositorio
```  shell-script
$ git clone https://github.com/PayTpv/Mage_PayTpv.git
```
- Añadimos a un fichero comprimido el fihero package.xml y los direcotiros app y skin. En línux podríamos usar el script build_package.sh
```  shell-script
$ cd Mage_PayTpv
$ ./build_package.sh
```   
- Desde la administración de magento accedemos a Sistema -> Magento Connect -> Magento Connect Manager -> Direct package file upload Seleccionamos el fichero comprimido que acabamos de crear y le damos a "upload".
  
### Vía modman
- Instalar [modman](https://github.com/colinmollenhour/modman)
- Usar el siguiente comando de línea desde el directorio de instalación de Magento:
```
$ modman clone https://github.com/PayTpv/Mage_PayTpv.git
```

## Configuración del producto en PayTPV

Accedemos a nuestro area de clientes en https://paytpv.com/ → Mis productos → configurar productos Y seleccionamos el producto que vayamos a configurar en nuestra tienda magento.

En _tipo de notificación_ Marcamos _Notificación por URL_ o _Notificación por URL y por email_, finalmente en _URL Notificación_ ponemos lo siguiente:
```
{tudominio.com}/{dir_magento}/index.php/paytpvcom/standard/callback
```

Poniendo nuestro dominio en lugar de {tudominio.com} y el directorio en el que esté instaldo magento en lugar de {dir_magento}. Si Magento está instalado en la raíz se omitirá {dir_magento}/ 

## Configuración del Módulo

Accederemos a la configuración del módulo a través de Administración → Sistema → Configuración → Métodos de Pago → "Tarjeta de crédito paytpv". Ahí tendremos que indicar el Código de cliente, Número de terminal y Contraseña de usuario de nuestro producto en PayTPV.com.


### Operativa BANKSTORE

Esta operativa nos permite integrar completamente la experiencia de compra dentro de nuestra tienda Magento. Necesitaremos tener contratado un terminal de tipo BANKSTORE para poder usarla.
    
Si es la primera compra del cliente en nuestra tienda, al seleccionar "Tarjeta de crédito" como método de pago, se le solicitarán los datos de la tarjeta, y sólo cuando confirme el pedido se le realizará el cargo.
  
Si el cliente ya ha pagado algún pedido anterior con este método de pago el cliente no tendrá que volver a introducir los datos de la tarjeta. Podrá seleccionar entre las tarjetas personales tokenizadas para realizar el pago. Cuando confirme el pedido se le hará el cargo en la tarjeta. Aún así en la elección de método de pago se le da la opción al cliente de introducir los datos de otra tarjeta si quiere realizar el pago con una tarjeta diferente a la que haya utilizado con anterioridad en esta tienda.
  
Con esta operativa conseguimos reducir los pasos para completar una compra al mínimo.

*Nota:* Esta operativa, al realizarse por WebServices, necesita que esté activado el móudlo SOAP en la configuración de PHP.
