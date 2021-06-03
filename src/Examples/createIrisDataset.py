#!/usr/bin/env python

# ----- Python modules used ------------------------------------------------------------------------------------------------------
import sys
import os
import pandas as pd
import numpy as np
import mysql.connector as msql
from mysql.connector import Error
# ----- Arguments parsing --------------------------------------------------------------------------------------------------------
host        = sys.argv[1]
username    = sys.argv[2]
password    = sys.argv[3]
database    = sys.argv[4]

# The path of the actual directory, where the file 'iris.csv' is present
dir_path = os.path.dirname(os.path.realpath(__file__))
# Create dataframe from csv file
irisData = pd.read_csv(r'' + dir_path + '/iris.csv')
# Shuffle rows
irisData = irisData.sample(frac=1)
# Add index column starting from 1
irisData.index = np.arange(1, len(irisData) + 1)
irisData.reset_index(inplace=True)
print(irisData.head())
# Create Iris table in the database and inserting data from Iris dataframe
try:
    conn = msql.connect(host=host, database=database, user=username, password=password)
    if conn.is_connected():
        cursor = conn.cursor()
        cursor.execute("select database();")
        record = cursor.fetchone()
        print("You're connected to database: ", record)
        cursor.execute('DROP TABLE IF EXISTS iris;')
        print('Creating table....')
        cursor.execute("CREATE TABLE iris (id INT NOT NULL, \
            sepal_length FLOAT(2,1) NOT NULL, \
        	sepal_width FLOAT(2,1) NOT NULL, \
            petal_length FLOAT(2,1) NOT NULL, \
        	petal_width FLOAT(2,1), \
            species CHAR(11)NOT NULL)")
        print("iris table is created....")
        for i,row in irisData.iterrows():
            sql = "INSERT INTO " + database + ".iris VALUES (%s,%s,%s,%s,%s,%s)"
            cursor.execute(sql, tuple(row))
            print("Record inserted")
            # the connection is not autocommitted by default, so we must commit to save our changes
            conn.commit()
except Error as e:
    print("Error while connecting to MySQL", e)