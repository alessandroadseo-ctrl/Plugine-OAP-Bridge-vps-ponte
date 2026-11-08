import sys
from pathlib import Path
from decimal import Decimal
from jinja2 import Environment, FileSystemLoader, StrictUndefined, select_autoescape
# Read-only source: vps-ponte main 28978cd475469e999abf53682742290c96902ea0.
root=Path(sys.argv[1]) / 'app/renderer/templates'
e=Environment(loader=FileSystemLoader(root),undefined=StrictUndefined,autoescape=select_autoescape(['html']))
e.filters['money']=lambda v,currency='EUR': f'{Decimal(v):,.2f}'.translate(str.maketrans({',':'.','.':','}))+' €'
e.filters['decimal_money']=lambda v: format(Decimal(v),'.2f')
p=dict(image='https://images.test/product.jpg',title="Prodotto & accessori ' speciali",category='Elettronica',short_description='Sintesi',description='Descrizione completa')
offers=[]
for i,(price,shipping) in enumerate([('7.99',None),('8.99','5.00'),('9.99','0.00')]):
 offers.append(dict(price=price,shipping=shipping,total=format(Decimal(price)+Decimal(shipping),'.2f') if shipping is not None else None,currency='EUR',merchant_logo='https://images.test/logo.png',merchant_name=f'Negozio {i}',description=f'Descrizione {i}',availability='in_stock',affiliate_url='https://receiver.test/go/?id='+chr(65+i)*43))
html=e.get_template('product.html').render(p=p,offers=offers,minimum=Decimal('7.99'),best_offer_known=True,brand_name='Marca',family_name='Famiglia',display_gtin='00012345678905',labels={'in_stock':'Disponibile'})
html=html.replace('&#34;','&#034;').replace('&#39;','&#039;')
for name in ['shipping-toggle.html','vps2-shipping.html']:
 (Path(__file__).parent / 'fixtures' / name).write_text(html)
